#!/usr/bin/env python3
"""BMS agent: runs backups and restore tests on this machine when the BMS server asks.

Design rules (they are the point of this file):
  * It only ever calls BMS (outbound HTTPS). Nothing listens on this machine.
  * BMS sends the NAME of an action. What that name runs is decided by the local config file, so a
    compromised BMS can start the actions you defined here and nothing else.
  * The one exception is opt-in: with "remote_actions": true in the config (the install default), BMS may also
    define command actions, edited by an admin on the Agents page. That lets an admin change what runs without
    touching the server, and it means anyone who controls BMS can run commands here. Install with
    --no-remote-actions to keep the file as the only authority.
  * Commands are run as an argument list, never through a shell.
  * Python 3.8+, standard library only. Works the same on Linux and Windows.
"""
import argparse
import getpass
import hashlib
import json
import logging
import os
import platform
import random
import re
import shutil
import signal
import ssl
import stat
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.request

VERSION = "1.9.0"
OUTPUT_TAIL = 4000          # characters of command output sent back to BMS
HTTP_TIMEOUT = 30           # seconds for each call to BMS
DEFAULT_BACKUP_SECONDS = 8 * 3600
DEFAULT_TIMEOUT = 8 * 3600  # seconds a command may run when its config gives no timeout
MAX_FILES_SCANNED = 200_000

log = logging.getLogger("bms-agent")
stop = threading.Event()


def default_config_path():
    if os.name == "nt":
        return os.path.join(os.environ.get("ProgramData", r"C:\ProgramData"), "bms-agent", "config.json")
    return "/etc/bms-agent/config.json"


class ConfigError(Exception):
    pass


def validate_config(cfg):
    """Check a config and fill in its defaults. Raises ConfigError with a message an admin can act on."""
    for key in ("bms_url", "token"):
        if not isinstance(cfg.get(key), str) or not cfg[key]:
            raise ConfigError("config needs a non-empty '%s'" % key)
    cfg["bms_url"] = cfg["bms_url"].rstrip("/")
    if not cfg["bms_url"].startswith("https://") and not cfg.get("allow_insecure_http"):
        raise ConfigError("bms_url must be https:// (the agent secret is sent with every call). "
                          "Set \"allow_insecure_http\": true only for local testing.")
    cfg["poll_seconds"] = max(5, int(cfg.get("poll_seconds", 30)))
    cfg.setdefault("verify_tls", True)

    cfg["remote_actions"] = bool(cfg.get("remote_actions", False))
    actions = cfg.get("actions", {})
    if not isinstance(actions, dict) or (not actions and not cfg["remote_actions"]):
        raise ConfigError("config needs an 'actions' object with at least one action (or \"remote_actions\": true, so BMS supplies them)")
    for name, a in actions.items():
        if not isinstance(a, dict) or a.get("type") not in ("command", "restore_test"):
            raise ConfigError("action '%s': type must be 'command' or 'restore_test'" % name)
        if a["type"] == "command":
            cmd = a.get("command")
            if not (isinstance(cmd, list) and cmd and all(isinstance(x, str) for x in cmd)):
                raise ConfigError("action '%s': 'command' must be a list of strings, e.g. [\"/usr/local/bin/backup.sh\"]. "
                                  "It is run directly, not through a shell." % name)
        else:
            # With "locations" below, BMS names the location per job and no path is needed here. Without them, the action carries one path.
            if not cfg.get("locations") and (not isinstance(a.get("path"), str) or not a["path"]):
                raise ConfigError("action '%s': a restore_test needs a 'path' (the folder holding the backup), or add a 'locations' map" % name)

    locs = cfg.get("locations", {})
    if not isinstance(locs, dict) or not all(isinstance(k, str) and isinstance(v, str) and v for k, v in locs.items()):
        raise ConfigError("'locations' must map a location key to a folder path, e.g. {\"nas-main\": \"/mnt/nas/backups\"}")
    cfg["locations"] = locs

    roots = cfg.get("source_roots", [])
    if not isinstance(roots, list) or not all(isinstance(r, str) and r for r in roots):
        raise ConfigError("'source_roots' must be a list of folders this agent may read when comparing a backup with its source, e.g. [\"/var/www\"]")
    cfg["source_roots"] = roots
    return cfg


def load_config(path):
    try:
        with open(path, "r", encoding="utf-8") as f:
            cfg = json.load(f)
    except FileNotFoundError:
        raise ConfigError("config file not found: %s" % path)
    except ValueError as e:
        raise ConfigError("config file is not valid JSON: %s" % e)
    cfg = validate_config(cfg)

    if os.name != "nt":
        mode = os.stat(path).st_mode
        if mode & (stat.S_IRWXG | stat.S_IRWXO):
            log.warning("config file %s is readable by other users and holds the agent secret. Run: chmod 600 %s", path, path)
    return cfg


# --- talking to BMS -----------------------------------------------------------------------------

class ApiError(Exception):
    def __init__(self, status, message):
        super().__init__("BMS answered %s: %s" % (status, message))
        self.status = status


def api(cfg, path, body):
    ctx = ssl.create_default_context() if cfg["verify_tls"] else ssl._create_unverified_context()
    req = urllib.request.Request(
        cfg["bms_url"] + path, data=json.dumps(body).encode("utf-8"), method="POST",
        headers={"Authorization": "Bearer " + cfg["token"], "Content-Type": "application/json", "Accept": "application/json",
                 "User-Agent": "bms-agent/" + VERSION})
    try:
        with urllib.request.urlopen(req, timeout=HTTP_TIMEOUT, context=ctx) as r:
            return json.loads(r.read().decode("utf-8") or "{}")
    except urllib.error.HTTPError as e:
        try:
            msg = json.loads(e.read().decode("utf-8")).get("error", e.reason)
        except Exception:
            msg = e.reason
        raise ApiError(e.code, msg)


ACTION_NAME = re.compile(r"^[A-Za-z0-9._-]{1,100}$")


def clean_managed(raw):
    """The command actions an admin set in BMS, checked here again: only 'command' type, a plain argument list, sane numbers.
    Anything malformed is dropped, so a bad entry can never stop the agent. An entry with no command is a placeholder."""
    out = {}
    if not isinstance(raw, dict):
        return out
    for name, a in raw.items():
        if not (isinstance(name, str) and ACTION_NAME.match(name) and isinstance(a, dict)):
            continue
        cmd = a.get("command")
        if cmd is None or cmd == []:
            out[name] = {"type": "command", "command": None}
            continue
        if not (isinstance(cmd, list) and all(isinstance(x, str) for x in cmd) and cmd[0].strip()):
            continue
        entry = {"type": "command", "command": cmd}
        if isinstance(a.get("cwd"), str) and a["cwd"]:
            entry["cwd"] = a["cwd"]
        t = a.get("timeout_seconds")
        if isinstance(t, int) and not isinstance(t, bool) and 60 <= t <= 7 * 86400:
            entry["timeout_seconds"] = t
        out[name] = entry
    return out


def effective_actions(cfg):
    """What this agent can do right now: the file's actions, plus (when allowed) the ones BMS manages. A managed action with a
    command replaces a file action of the same name; one without a command (the unconfigured default) never hides a file action.
    A restore_test action in the file cannot be replaced from BMS."""
    actions = dict(cfg["actions"])
    for kind in ("robocopy", "rsync"):
        if kind not in actions and builtin_available(kind):
            actions[kind] = {"type": kind}
    if cfg.get("remote_actions"):
        for name, m in cfg.get("_managed", {}).items():
            if (actions.get(name) or {}).get("type") == "restore_test":
                continue
            if m["command"] is None and name in actions:
                continue
            actions[name] = m
    return actions


LOCATION_KEY = re.compile(r"^[a-z0-9]+(-[a-z0-9]+)*$")


def clean_managed_locations(raw):
    """Folders an admin mapped to location keys in BMS. Only a plain key and an absolute path are taken; anything else is dropped."""
    out = {}
    if not isinstance(raw, dict):
        return out
    for key, path in raw.items():
        if isinstance(key, str) and LOCATION_KEY.match(key) and len(key) <= 100 and isinstance(path, str) and 0 < len(path) <= 512 \
                and (os.path.isabs(path) or path.startswith("\\\\")) and "\0" not in path:
            out[key] = path
    return out


def clean_managed_roots(raw):
    """Folders an admin allowed this agent to read, set in BMS. Only absolute paths without '..' are taken."""
    out = []
    if not isinstance(raw, list):
        return out
    for p in raw:
        if isinstance(p, str) and 0 < len(p) <= 512 and "\0" not in p and (os.path.isabs(p) or p.startswith("\\\\")) \
                and ".." not in re.split(r"[\\/]+", p) and p not in out:
            out.append(p)
    return out


def effective_source_roots(cfg):
    """The config file's source roots plus (when remote actions are on) the ones set in BMS."""
    roots = list(cfg["source_roots"])
    if cfg.get("remote_actions"):
        roots += [r for r in cfg.get("_managed_roots", []) if r not in roots]
    return roots


# --- credentials for network shares ------------------------------------------------------------
# An admin can give BMS a user name and password for a location's network share (Agents page, Edit). BMS sends them over
# HTTPS only when they change; they live in this process's memory and are never written to disk or the log. On Windows the
# agent signs in to the share through the operating system directly (no "net use", so the password is never on a command line).
# Linux locations are mounted folders, so the mount (fstab or a credentials file) is where their login belongs.

def clean_managed_credentials(raw):
    out = {}
    if not isinstance(raw, dict):
        return out
    for key, c in raw.items():
        if isinstance(key, str) and LOCATION_KEY.match(key) and isinstance(c, dict):
            user, pw = c.get("username"), c.get("password")
            if isinstance(user, str) and 0 < len(user) <= 200 and isinstance(pw, str) and 0 < len(pw) <= 500:
                out[key] = {"username": user, "password": pw}
    return out


def share_root(path):
    """\\\\server\\share from any path below it, or None when the path is not a network share."""
    m = re.match(r"^\\\\([^\\/]+)[\\/]+([^\\/]+)", path or "")
    return "\\\\%s\\%s" % (m.group(1), m.group(2)) if m else None


def _windows_message(code):
    try:
        import ctypes
        return ctypes.FormatError(code).strip()
    except Exception:  # noqa: BLE001
        return "Windows error %d" % code


def _wnet_connect(share, user, password):
    """Sign in to a share with this account. Returns 0 on success or a Windows error code. The password goes to the OS, not a command line."""
    import ctypes
    from ctypes import wintypes

    class NetResource(ctypes.Structure):
        _fields_ = [("dwScope", wintypes.DWORD), ("dwType", wintypes.DWORD), ("dwDisplayType", wintypes.DWORD), ("dwUsage", wintypes.DWORD),
                    ("lpLocalName", wintypes.LPWSTR), ("lpRemoteName", wintypes.LPWSTR), ("lpComment", wintypes.LPWSTR), ("lpProvider", wintypes.LPWSTR)]

    mpr = ctypes.WinDLL("mpr")
    res = NetResource()
    res.dwType = 1  # a disk share
    res.lpRemoteName = share
    rc = mpr.WNetAddConnection2W(ctypes.byref(res), password, user, 0)
    if rc == 1219:  # already signed in to this server as someone else: drop that and use the account BMS holds
        mpr.WNetCancelConnection2W(share, 0, True)
        rc = mpr.WNetAddConnection2W(ctypes.byref(res), password, user, 0)
    return rc


def ensure_location_access(cfg, location):
    """Sign in to the location's share with the credentials BMS holds for it. None when nothing is needed or it worked, else a message."""
    cred = cfg.get("_credentials", {}).get(location) if cfg.get("remote_actions") else None
    share = share_root(effective_locations(cfg).get(location)) if cred else None
    if not cred or not share or os.name != "nt":
        return None
    rc = _wnet_connect(share, cred["username"], cred["password"])
    if rc in (0, 85):  # 85: this connection already exists
        return None
    return "Could not sign in to %s as %s: %s (code %d)." % (share, cred["username"], _windows_message(rc), rc)


def effective_locations(cfg):
    """The config file's locations, plus (when remote actions are on) the ones set in BMS. The file wins for a key it defines."""
    locs = dict(cfg["locations"])
    if cfg.get("remote_actions"):
        for key, path in cfg.get("_managed_locations", {}).items():
            locs.setdefault(key, path)
    return locs


def action_timeout(a):
    """Seconds an action may run before this agent stops it. BMS uses this to know when to give up waiting, so a backup that
    is allowed 24 hours is not written off after 8."""
    if a["type"] in ("robocopy", "rsync"):
        return DEFAULT_BACKUP_SECONDS
    return int(a.get("timeout_seconds", DEFAULT_TIMEOUT if a["type"] == "command" else 300))


def advertised_actions(cfg):
    out = {}
    for name, a in effective_actions(cfg).items():
        out[name] = {"type": a["type"], "timeout_seconds": action_timeout(a)}
        if a["type"] == "restore_test":
            out[name].update(tests=list(SUPPORTED_TESTS), test_timeouts=test_timeouts(a))
    return out


def poll(cfg, busy=False):
    body = {"version": VERSION, "os": "%s %s" % (platform.system(), platform.release()), "actions": advertised_actions(cfg),
            "locations": sorted(effective_locations(cfg)), "source_roots": effective_source_roots(cfg), "remote_actions": cfg["remote_actions"],
            "credentials_version": cfg.get("_credentials_version", 0)}
    if busy:
        body["busy"] = True
    answer = api(cfg, "/api/agent/poll", body)
    if cfg["remote_actions"] and "managed_actions" in answer:
        cfg["_managed"] = clean_managed(answer["managed_actions"])
    if cfg["remote_actions"] and "managed_locations" in answer:
        cfg["_managed_locations"] = clean_managed_locations(answer["managed_locations"])
    if cfg["remote_actions"] and "managed_source_roots" in answer:
        cfg["_managed_roots"] = clean_managed_roots(answer["managed_source_roots"])
    # Sent only when they changed. Kept in memory, never written anywhere.
    if cfg["remote_actions"] and "managed_credentials" in answer:
        cfg["_credentials"] = clean_managed_credentials(answer["managed_credentials"])
        cfg["_credentials_version"] = int(answer.get("credentials_version", 0))
    return answer.get("command")


def report(cfg, command_id, status, exit_code=None, output=None, details=None):
    body = {"status": status}
    if exit_code is not None:
        body["exit_code"] = exit_code
    if output:
        body["output"] = output[-OUTPUT_TAIL:]
    if details:
        body["details"] = details
    api(cfg, "/api/agent/commands/%d/result" % command_id, body)


REPORT_RETRY_DELAYS = (5, 15, 30, 60, 120, 300, 600, 600)  # seconds between tries: about half an hour in all, inside BMS's wait


def report_with_retry(cfg, command_id, status, **kw):
    """Send a command's result, and keep trying while BMS is unreachable or erroring. Returns True once it was accepted.
    A refusal (BMS says the command is already finished or expired) is final: sending it again cannot change the answer."""
    for delay in (0,) + REPORT_RETRY_DELAYS:
        if delay and stop.wait(delay):
            log.error("stopping before the result of command %s could be delivered", command_id)
            return False
        try:
            report(cfg, command_id, status, **kw)
            return True
        except ApiError as e:
            if 400 <= e.status < 500 and e.status not in (408, 429):
                log.error("BMS did not accept the result of command %s: %s", command_id, e)
                return False
            log.warning("could not deliver the result of command %s (%s), trying again", command_id, e)
        except (urllib.error.URLError, OSError, ssl.SSLError) as e:
            log.warning("could not reach BMS to deliver the result of command %s (%s), trying again", command_id, e)
    log.error("gave up delivering the result of command %s", command_id)
    return False


# --- action: command ----------------------------------------------------------------------------

def run_command(action):
    if not action.get("command"):
        return "failed", None, "This action has no command yet. An admin sets it on the Agents page in BMS (Actions).", None
    timeout = int(action.get("timeout_seconds", DEFAULT_TIMEOUT))
    try:
        p = subprocess.run(action["command"], cwd=action.get("cwd") or None, stdin=subprocess.DEVNULL,
                           stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=timeout, shell=False)
    except subprocess.TimeoutExpired as e:
        tail = (e.stdout or b"").decode("utf-8", "replace")
        return "failed", None, ("%s\nTimed out after %d seconds." % (tail, timeout)).strip(), None
    except OSError as e:
        return "failed", None, "Could not start the command: %s" % e, None
    out = p.stdout.decode("utf-8", "replace")
    return ("success" if p.returncode == 0 else "failed"), p.returncode, out, None


# --- action: robocopy / rsync (built in) --------------------------------------------------------
# For a job whose method is robocopy or rsync, BMS sends the job's folders, exclusions, layout, location key and destination
# folder. The agent builds the command itself, so nobody repeats the folders in a script. BMS never sends a command line:
# the program is always robocopy or rsync, the arguments are built here, and every path is checked against this machine's
# own allowlists (source_roots for what may be read, locations for where it may be written).
# Copy only: nothing is ever deleted at the destination, so a wrong source folder cannot wipe good backups.

def builtin_available(kind):
    if kind == "robocopy":
        return os.name == "nt" and shutil.which("robocopy") is not None
    return os.name != "nt" and shutil.which("rsync") is not None


def run_as():
    try:
        return getpass.getuser()
    except Exception:  # noqa: BLE001
        return os.environ.get("USERNAME") or os.environ.get("USER") or "this service account"


def folder_problem(path, what):
    """None when path is a folder this agent can open, else a message that says what to do about it.
    Windows only says 'access denied' for a network share the service account cannot see, so that case spells out the fix."""
    try:
        st = os.stat(path)
    except PermissionError:
        hint = ""
        if os.name == "nt" and path.startswith("\\\\"):
            hint = (" This is a network share. The agent runs as '%s'; the SYSTEM account has no network login, so the share sees it as this "
                    "computer's account (DOMAIN\\COMPUTERNAME$). Give that account Modify rights on the share and folder, or run the BMS Agent task as an account "
                    "that already has access." % run_as())
        return "Access denied to %s '%s'.%s" % (what, path, hint)
    except FileNotFoundError:
        return ("%s '%s' does not exist, or this agent (running as '%s') cannot see it. The agent never creates a location's own folder, "
                "so a mistyped path cannot start a backup in the wrong place. Create it, or fix the path on the Agents page." % (what.capitalize(), path, run_as()))
    except OSError as e:
        return "%s '%s' cannot be opened: %s" % (what.capitalize(), path, e)
    if not stat.S_ISDIR(st.st_mode):
        return "%s '%s' is not a folder." % (what.capitalize(), path)
    return None


def backup_target(layout, src, dest):
    """Where one source folder lands inside the destination folder, for the job's layout (the same rules map_to_source reverses)."""
    if layout == "contents":
        return dest
    if layout == "by_folder_name":
        name = os.path.basename(os.path.normpath(src))
        if not name or name.endswith(":"):
            raise ValueError("'%s' has no folder name to use as a sub-folder. Back up a folder, not a drive root." % src)
        return os.path.join(dest, name)
    if layout == "full_path":
        if os.name == "nt":
            m = re.match(r"^([A-Za-z]):[\\/]*(.*)$", src)
            if not m:
                raise ValueError("the 'full path' layout needs a drive-letter folder on Windows, not '%s'." % src)
            return os.path.join(dest, m.group(1).upper(), *[p for p in re.split(r"[\\/]+", m.group(2)) if p])
        return os.path.join(dest, *[p for p in src.split("/") if p])
    raise ValueError("unknown layout '%s'" % layout)


def build_backup_command(kind, src, target, excluded):
    """The argument list for one source folder. Exclusions are only those inside this folder."""
    inner = [e for e in excluded if inside(e, src) and _norm(e) != _norm(src)]
    if kind == "robocopy":
        if re.match(r"^[A-Za-z]:[\\/]*$", src):
            raise ValueError("'%s' is a drive root. Robocopy cannot take one safely, so back up folders on it instead." % src)
        cmd = ["robocopy", src, target, "/E", "/COPY:DAT", "/DCOPY:DAT", "/R:2", "/W:5", "/NP"]
        dirs = [e for e in inner if os.path.isdir(e)]
        files = [e for e in inner if not os.path.isdir(e)]
        if dirs:
            cmd += ["/XD"] + dirs
        if files:
            cmd += ["/XF"] + files
        return cmd
    cmd = ["rsync", "-a", "--partial"]
    for e in inner:
        rel = os.path.relpath(e, src).replace(os.sep, "/")
        cmd.append("--exclude=/" + re.sub(r"([\\*?\[])", r"\\\1", rel))
    return cmd + ["--", src.rstrip("/") + "/", target.rstrip("/") + "/"]


def backup_ok(kind, code):
    # Robocopy: 0-7 mean files were copied, skipped or are extra; 8 and up mean something failed.
    # rsync: 24 means some files vanished while it ran, which happens on a live server and is not a failure.
    return code < 8 if kind == "robocopy" else code in (0, 24)


def run_builtin_backup(cfg, kind, cmd):
    if not builtin_available(kind):
        return "failed", None, "%s is not installed on this machine, so BMS cannot run this backup here." % kind, None
    job = cmd.get("source") or {}
    folders, excluded, layout = job.get("folders") or [], job.get("excluded") or [], job.get("layout")
    if not (isinstance(folders, list) and folders and all(isinstance(f, str) and f for f in folders)) or not isinstance(excluded, list):
        return "failed", None, "BMS did not send the folders to back up.", None
    roots = effective_source_roots(cfg)
    for f in folders:
        if not any(inside(f, r) for r in roots):
            return "failed", None, ("'%s' is not under any of this agent's source_roots, so it will not be read. "
                                    "Allow its folder on the Agents page in BMS, or give --source-root when installing." % f), None
        if not os.path.isdir(f):
            return "failed", None, "The source folder '%s' does not exist on this machine." % f, None
    dest, why = restore_path(cfg, {}, cmd.get("location"), cmd.get("subpath"))
    if dest is None or not cmd.get("location"):
        return "failed", None, why or "The job has no backup location.", None
    # The location's own folder must already exist. Only the job's folders below it are created.
    problem = folder_problem(effective_locations(cfg)[cmd["location"]], "the backup location folder")
    if problem:
        return "failed", None, problem, None
    try:
        os.makedirs(dest, exist_ok=True)
        plan = [(f, build_backup_command(kind, f, backup_target(layout, f, dest), excluded)) for f in folders]
    except PermissionError as e:
        return "failed", None, "Access denied creating '%s' inside the backup location: %s" % (dest, e), None
    except (ValueError, OSError) as e:
        return "failed", None, str(e), None
    deadline = time.monotonic() + DEFAULT_BACKUP_SECONDS
    out, ok = [], True
    for f, argv in plan:
        left = deadline - time.monotonic()
        if left <= 0:
            out.append("Out of time before %s." % f)
            ok = False
            continue
        try:
            p = subprocess.run(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=left, shell=False)
        except subprocess.TimeoutExpired:
            out.append("== %s: timed out" % f)
            ok = False
            continue
        except OSError as e:
            out.append("== %s: could not start %s: %s" % (f, kind, e))
            ok = False
            continue
        good = backup_ok(kind, p.returncode)
        ok = ok and good
        out.append("== %s -> exit %d (%s)\n%s" % (f, p.returncode, "ok" if good else "failed", p.stdout.decode("utf-8", "replace")[-OUTPUT_TAIL // max(1, len(plan)):]))
    return ("success" if ok else "failed"), None, "\n".join(out), None


# --- action: restore_test -----------------------------------------------------------------------

class Timeout(Exception):
    pass


def sha256(path, deadline):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            if time.monotonic() > deadline:
                raise Timeout()
            h.update(chunk)
    return h.hexdigest()


def pick_random_files(root, deadline, k=1):
    """Up to k files chosen uniformly at random from a tree (reservoir sampling, so the tree is never listed in memory).
    Symlinks are skipped. Returns them in random order."""
    reservoir, seen, stack = [], 0, [root]
    while stack and seen < MAX_FILES_SCANNED:
        if time.monotonic() > deadline:
            raise Timeout()
        d = stack.pop()
        try:
            entries = list(os.scandir(d))
        except OSError:
            if d == root:
                raise
            continue  # an unreadable subfolder is skipped
        for e in entries:
            try:
                if e.name.startswith(CHECK_PREFIX) or e.is_symlink():
                    continue
                if e.is_dir(follow_symlinks=False):
                    stack.append(e.path)
                elif e.is_file(follow_symlinks=False):
                    seen += 1
                    if len(reservoir) < k:
                        reservoir.append(e.path)
                    else:
                        j = random.randint(1, seen)
                        if j <= k:
                            reservoir[j - 1] = e.path
            except OSError:
                continue
    random.shuffle(reservoir)
    return reservoir


def pick_random_file(root, deadline):
    files = pick_random_files(root, deadline, 1)
    return files[0] if files else None


def fail(failure_type, severity, item, message, **extra):
    d = {"failure_type": failure_type, "severity": severity, "failed_item": item, "error_message": message}
    d.update(extra)
    return "failed", None, message, d


# --- comparing a backup file with its source ----------------------------------------------------

SOURCE_CANDIDATES = 10      # how many random backup files to try before settling for an integrity-only test
MTIME_TOLERANCE = 2.0       # seconds: FAT file systems store modification times to the nearest 2 seconds


def _norm(path):
    return os.path.normcase(os.path.normpath(path))


def inside(child, parent):
    """True when child is parent or lies below it, ignoring case on Windows."""
    c, p = _norm(child), _norm(parent)
    return c == p or c.startswith(p.rstrip(os.sep) + os.sep)


def map_to_source(rel_parts, layout, includes):
    """Trace a backup file, given as path parts relative to the destination folder, to the file it was copied from.
    The layout says how the backup tool placed the source in the destination. Returns (path, None) or (None, reason)."""
    if layout == "contents":
        if len(includes) != 1:
            return None, "layout 'contents' needs exactly one source folder"
        return os.path.join(includes[0], *rel_parts), None
    if layout == "by_folder_name":
        if len(rel_parts) < 2:
            return None, "the file is not inside a sub-folder named after a source folder"
        matches = [i for i in includes if os.path.normcase(os.path.basename(os.path.normpath(i))) == os.path.normcase(rel_parts[0])]
        if len(matches) != 1:
            return None, "no single source folder is named '%s'" % rel_parts[0]
        return os.path.join(matches[0], *rel_parts[1:]), None
    if layout == "full_path":
        if os.name == "nt":
            m = re.match(r"^([A-Za-z])[:$]?$", rel_parts[0])
            if not m or len(rel_parts) < 2:
                return None, "the first folder is not a drive letter"
            return os.path.join(m.group(1).upper() + ":" + os.sep, *rel_parts[1:]), None
        return os.sep + os.path.join(*rel_parts), None
    return None, "unknown layout '%s'" % layout


def classify_candidate(backup_file, root, source, source_roots):
    """Decide whether one backup file can be compared with its source. Returns ('ok', source_path) or (reason_key, note)."""
    rel_parts = os.path.relpath(backup_file, root).split(os.sep)
    includes, excluded = source.get("folders") or [], source.get("excluded") or []
    path, why = map_to_source(rel_parts, source.get("layout"), includes)
    if path is None:
        return "unmapped", why
    if includes and not any(inside(path, i) for i in includes):
        return "unmapped", "it maps outside the folders the job backs up"
    if any(inside(path, x) for x in excluded):
        return "excluded", "it maps into an excluded folder"
    real = os.path.realpath(path)
    if not any(inside(real, os.path.realpath(r)) for r in source_roots):
        return "outside", "the source is outside this agent's allowed source folders"
    if not os.path.isfile(real):
        return "missing", "the source file no longer exists"
    try:
        if os.stat(real).st_mtime > os.stat(backup_file).st_mtime + MTIME_TOLERANCE:
            return "changed", "the source file was modified after it was backed up"
    except OSError:
        return "missing", "the source file could not be read"
    return "ok", real


def summarize_skips(counts, tried):
    names = {"changed": "changed since the backup", "missing": "no longer exist", "unmapped": "could not be traced to a source",
             "excluded": "map into excluded folders", "outside": "are outside the allowed source folders"}
    parts = ["%d %s" % (n, names[k]) for k, n in counts.items() if n]
    return "Tried %d random file(s) and none could be compared with its source: %s." % (tried, "; ".join(parts) or "unknown reason")


def run_restore_test(action, root, source=None, source_roots=()):
    """Pick a random file in the backup, copy it to a staging folder as a restore, compare SHA-256, clean up. When BMS
    says how the job is laid out and this agent has source folders it may read, prefer a file whose source can be found
    and unchanged, and also compare the restored copy with that source file."""
    deadline = time.monotonic() + int(action.get("timeout_seconds", 300))
    staging = None
    want_source = bool(source and source.get("layout"))
    try:
        if not os.path.isdir(root):
            return fail("file_missing", "high", root, "The backup location does not exist or is not reachable.")
        candidates = pick_random_files(root, deadline, SOURCE_CANDIDATES if want_source and source_roots else 1)
        if not candidates:
            return fail("file_missing", "high", root, "The backup location contains no files to restore.")

        picked, src_path, skips, note = candidates[0], None, {}, None
        if want_source and not source_roots:
            note = "This agent has no 'source_roots' in its config, so it does not read source files."
        elif want_source:
            for c in candidates:
                kind, info = classify_candidate(c, root, source, source_roots)
                if kind == "ok":
                    picked, src_path = c, info
                    break
                skips[kind] = skips.get(kind, 0) + 1
            if src_path is None:
                note = summarize_skips(skips, len(candidates))

        backup_hash = sha256(picked, deadline)
        staging = tempfile.mkdtemp(prefix="bms-restore-", dir=action.get("staging_dir") or None)
        restored = os.path.join(staging, "restored")
        shutil.copyfile(picked, restored)
        restored_hash = sha256(restored, deadline)
        if backup_hash != restored_hash:
            return fail("hash_mismatch", "critical", picked, "The restored file does not match the backup copy.",
                        hash_algorithm="sha256", hash_original=backup_hash, hash_restored=restored_hash)

        details = {"hash_algorithm": "sha256", "hash_original": backup_hash, "hash_restored": restored_hash, "failed_item": picked}
        if src_path is not None:
            source_hash = sha256(src_path, deadline)
            # The source could have been edited while it was being read. Then it proves nothing either way.
            if os.stat(src_path).st_mtime > os.stat(picked).st_mtime + MTIME_TOLERANCE:
                note = "The source file changed while it was being compared, so it was not used."
            elif source_hash != restored_hash:
                return fail("hash_mismatch", "critical", picked,
                            "The backup copy differs from its source file, and the source had not changed since the backup.",
                            hash_algorithm="sha256", hash_original=source_hash, hash_restored=restored_hash,
                            source_status="mismatch", source_note="Compared with %s" % src_path)
            else:
                details.update(hash_original=source_hash, source_status="verified", source_note="Compared with %s" % src_path)
        if "source_status" not in details and want_source:
            details.update(source_status="not_comparable", source_note=note)
        return "success", 0, "Restored and verified %s" % picked, details
    except Timeout:
        return fail("timeout", "high", root, "The restore test timed out.")
    except PermissionError as e:
        return fail("access_denied", "high", getattr(e, "filename", None) or root, "Access was denied reading the backup.")
    except FileNotFoundError as e:
        return fail("file_missing", "high", getattr(e, "filename", None) or root, "A backup file disappeared during the test.")
    except Exception as e:  # noqa: BLE001 - report anything unexpected rather than crash the agent
        return fail("restore_failed", "high", root, "Restore failed: %s" % e)
    finally:
        if staging:
            shutil.rmtree(staging, ignore_errors=True)


# --- more tests: location write and read-back, full read-back, file permissions ------------------

CHECK_PREFIX = ".bms-check-"         # the hidden folder a write test makes; other tests ignore anything with this name
SYNTHETIC_BYTES = 256 * 1024
FULL_READ_SECONDS = 4 * 3600         # a whole-backup read takes far longer than restoring one file
PERMISSION_SAMPLES = 10
SUPPORTED_TESTS = ("random_restore", "synthetic_file", "full_recovery", "file_permissions")
READ_ONLY_ERRNOS = (30, 13, 1)       # EROFS, EACCES, EPERM


def test_timeouts(action):
    t = int(action.get("timeout_seconds", 300))
    return {"random_restore": t, "synthetic_file": t, "file_permissions": t,
            "full_recovery": int(action.get("full_read_timeout_seconds", FULL_READ_SECONDS))}


def human_bytes(n):
    n = float(n)
    for unit in ("bytes", "KB", "MB", "GB", "TB"):
        if n < 1024 or unit == "TB":
            return ("%d %s" % (n, unit)) if unit == "bytes" else ("%.1f %s" % (n, unit))
        n /= 1024


def skipped(reason):
    """The agent ran the test but found nothing it could judge. BMS records that as skipped, never as a pass."""
    return "success", 0, reason, {"skip_reason": reason}


def remove_tree(path):
    """Delete a folder this agent made. Returns True if it is gone."""
    shutil.rmtree(path, ignore_errors=True)
    return not os.path.exists(path)


def run_synthetic_file(action, root):
    """Write a known file into a hidden folder in the backup location, read it back, compare, and delete what was made. This tests the
    location (reachable, writable, returns data unchanged, how much space is left), not the backup software."""
    if not os.path.isdir(root):
        return fail("file_missing", "high", root, "The backup location does not exist or is not reachable.")
    folder = os.path.join(root, "%s%d-%s" % (CHECK_PREFIX, os.getpid(), os.urandom(4).hex()))
    result = None
    try:
        data = os.urandom(SYNTHETIC_BYTES)
        os.mkdir(folder)
        path = os.path.join(folder, "check.bin")
        with open(path, "wb") as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
        with open(path, "rb") as f:
            back = f.read()
        want, got = hashlib.sha256(data).hexdigest(), hashlib.sha256(back).hexdigest()
        try:
            free = shutil.disk_usage(root).free
        except OSError:
            free = None
        if want != got:
            result = fail("hash_mismatch", "critical", path, "What was written to the backup location did not read back the same.",
                          hash_algorithm="sha256", hash_original=want, hash_restored=got)
        else:
            summary = "Wrote and read back %s%s" % (human_bytes(SYNTHETIC_BYTES), ("; %s free" % human_bytes(free)) if free is not None else "")
            details = {"summary": summary, "hash_algorithm": "sha256", "hash_original": want, "hash_restored": got}
            if free is not None:
                details["free_bytes"] = free
            result = "success", 0, summary, details
    except PermissionError as e:
        result = fail("access_denied", "high", getattr(e, "filename", None) or root,
                      "The agent could not write to the backup location: access denied. It needs write access to run this test.")
    except OSError as e:
        kind = "access_denied" if e.errno in READ_ONLY_ERRNOS else "restore_failed"
        result = fail(kind, "high", getattr(e, "filename", None) or root,
                      "Writing to the backup location failed: %s" % (e.strerror or e))
    except Exception as e:  # noqa: BLE001 - always produce a result, so the test is not left waiting
        result = fail("restore_failed", "high", root, "The write test failed unexpectedly: %s" % e)
    if os.path.exists(folder) and not remove_tree(folder) and result[0] == "success":
        result[3]["summary"] += " (could not delete the test folder %s)" % folder
        result = result[0], result[1], result[3]["summary"], result[3]
    return result


def run_full_read(action, root):
    """Read every file in the backup completely and report how many were read and any that could not be. This shows each file can be read
    back in full (dead sectors, truncated files and permission problems show up). It restores nothing."""
    deadline = time.monotonic() + int(action.get("full_read_timeout_seconds", FULL_READ_SECONDS))
    if not os.path.isdir(root):
        return fail("file_missing", "high", root, "The backup location does not exist or is not reachable.")
    files = total = 0
    problems = []
    stack = [root]
    try:
        while stack and len(problems) < 20:
            if time.monotonic() > deadline:
                raise Timeout()
            d = stack.pop()
            try:
                entries = list(os.scandir(d))
            except OSError as e:
                problems.append((d, e))
                continue
            for e in entries:
                if e.name.startswith(CHECK_PREFIX):
                    continue
                try:
                    if e.is_symlink():
                        continue
                    if e.is_dir(follow_symlinks=False):
                        stack.append(e.path)
                    elif e.is_file(follow_symlinks=False):
                        with open(e.path, "rb") as f:
                            while True:
                                chunk = f.read(1 << 20)
                                if not chunk:
                                    break
                                total += len(chunk)
                                if time.monotonic() > deadline:
                                    raise Timeout()
                        files += 1
                except Timeout:
                    raise
                except OSError as ex:
                    problems.append((e.path, ex))
    except Timeout:
        return fail("timeout", "high", root, "Reading the whole backup took longer than allowed. %d files (%s) were read before time ran out." % (files, human_bytes(total)),
                    summary="Timed out after %d files" % files, files=files, bytes=total)
    if problems:
        path, err = problems[0]
        kind = "access_denied" if isinstance(err, PermissionError) else "corruption"
        more = "" if len(problems) == 1 else " (and %s more)" % ("20 or" if len(problems) >= 20 else len(problems) - 1)
        return fail(kind, "high", path, "%d file(s) could not be read in full. First: %s: %s%s" % (len(problems), path, err.strerror or err, more),
                    summary="%d unreadable, %d read" % (len(problems), files), files=files, bytes=total)
    if files == 0:
        return fail("file_missing", "high", root, "The backup location contains no files.")
    summary = "%d files, %s read, all readable" % (files, human_bytes(total))
    return "success", 0, summary, {"summary": summary, "files": files, "bytes": total}


def permission_view(path):
    """What this test compares for one file: mode, owner and group on Linux; the read-only, hidden and system flags on Windows."""
    st = os.stat(path)
    if os.name == "nt":
        return {"attributes": getattr(st, "st_file_attributes", 0) & 0x7}
    return {"mode": st.st_mode & 0o7777, "owner": st.st_uid, "group": st.st_gid}


def describe_permissions(view):
    if "attributes" in view:
        names = [n for bit, n in ((1, "read-only"), (2, "hidden"), (4, "system")) if view["attributes"] & bit]
        return "flags " + (",".join(names) or "none")
    return "mode %o owner %d group %d" % (view["mode"], view["owner"], view["group"])


def run_permission_check(action, root, source, source_roots):
    """Compare permissions of sampled backup files with their source files."""
    if not (source and source.get("layout")):
        return skipped("This job does not say how its folders are laid out, so backup files cannot be matched to their source files.")
    if not source_roots:
        return skipped("This agent has no folders it may read (source roots), so it cannot look at source files.")
    deadline = time.monotonic() + int(action.get("timeout_seconds", 300))
    if not os.path.isdir(root):
        return fail("file_missing", "high", root, "The backup location does not exist or is not reachable.")
    try:
        candidates = pick_random_files(root, deadline, PERMISSION_SAMPLES * 4)
    except Timeout:
        return fail("timeout", "high", root, "The permissions check timed out.")
    pairs, skips = [], {}
    for c in candidates:
        if c.startswith(os.path.join(root, CHECK_PREFIX)):
            continue
        kind, info = classify_candidate(c, root, source, source_roots)
        if kind == "ok":
            pairs.append((c, info))
            if len(pairs) >= PERMISSION_SAMPLES:
                break
        else:
            skips[kind] = skips.get(kind, 0) + 1
    if not pairs:
        return skipped("No sampled backup file had a source file this agent could compare: %s" % summarize_skips(skips, len(candidates)))
    differences = []
    for backup_file, src in pairs:
        try:
            a, b = permission_view(src), permission_view(backup_file)
        except OSError as e:
            return fail("access_denied" if isinstance(e, PermissionError) else "restore_failed", "medium", getattr(e, "filename", None) or root,
                        "Could not read the permissions of %s: %s" % (getattr(e, "filename", None) or backup_file, e.strerror or e))
        if a != b:
            differences.append((backup_file, a, b))
    if differences:
        f, a, b = differences[0]
        return fail("restore_failed", "medium", f,
                    "Permissions differ on %d of %d sampled files. For example %s: source has %s, backup has %s." % (len(differences), len(pairs), f, describe_permissions(a), describe_permissions(b)),
                    summary="%d of %d sampled files differ" % (len(differences), len(pairs)))
    summary = "Permissions match on %d sampled file%s" % (len(pairs), "" if len(pairs) == 1 else "s")
    return "success", 0, summary, {"summary": summary}


def run_agent_test(action, test_type, root, source, source_roots):
    if test_type == "synthetic_file":
        return run_synthetic_file(action, root)
    if test_type == "full_recovery":
        return run_full_read(action, root)
    if test_type == "file_permissions":
        return run_permission_check(action, root, source, source_roots)
    return run_restore_test(action, root, source, source_roots)


# --- main loop ----------------------------------------------------------------------------------

def restore_path(cfg, action, location, subpath=None):
    """Where to test. BMS names a location by key (and the job's folder inside it); this machine decides the path.
    Returns (path, None) or (None, reason)."""
    if location:
        root = effective_locations(cfg).get(location)
        if not root:
            return None, "This agent has no path for the location '%s'. Set its folder on the Agents page in BMS, or under 'locations' in the config file." % location
        if not subpath:
            return root, None
        # The sub-path must be relative and stay inside the location, even if BMS were compromised or a link points out.
        parts = subpath.split("/")
        if subpath.startswith("/") or any(p in ("", ".", "..") for p in parts) or re.match(r"^[A-Za-z]:$", parts[0]):
            return None, "BMS sent an invalid destination folder '%s'." % subpath
        path = os.path.join(root, *parts)
        if os.path.exists(path):
            real_root, real = os.path.realpath(root), os.path.realpath(path)
            if real != real_root and not real.startswith(real_root.rstrip(os.sep) + os.sep):
                return None, "The destination folder '%s' resolves outside the location, so it was not read." % subpath
        return path, None
    if action.get("path"):
        return action["path"], None
    return None, "The job has no backup location and this restore_test action has no 'path'."


def execute(cfg, cmd):
    """Run one command BMS handed out, and report the result. Never raises."""
    name, ctype = cmd.get("action"), cmd.get("type")
    action = effective_actions(cfg).get(name)
    expected = "command" if ctype == "backup" else "restore_test"
    try:
        builtin = ctype == "backup" and action is not None and action["type"] in ("robocopy", "rsync")
        if action is None or (action["type"] != expected and not builtin):
            log.error("BMS asked for '%s' (%s) but no such action is defined here", name, ctype)
            report_with_retry(cfg, cmd["id"], "failed", output="No action named '%s' of type '%s' is defined in this agent's config." % (name, expected))
            return
        log.info("running %s action '%s' (command %s)", ctype, name, cmd["id"])
        if cmd.get("location") and (builtin or action["type"] == "restore_test"):
            why = ensure_location_access(cfg, cmd["location"])
            if why:
                log.error("%s", why)
                report_with_retry(cfg, cmd["id"], "failed", output=why)
                return
        if builtin:
            status, code, output, details = run_builtin_backup(cfg, action["type"], cmd)
        elif action["type"] == "command":
            status, code, output, details = run_command(action)
        else:
            root, why = restore_path(cfg, action, cmd.get("location"), cmd.get("subpath"))
            if root is None:
                log.error("%s", why)
                report_with_retry(cfg, cmd["id"], "failed", output=why)
                return
            test_type = cmd.get("test_type") or "random_restore"
            if test_type not in SUPPORTED_TESTS:
                report_with_retry(cfg, cmd["id"], "failed", output="This agent cannot run the %s test. Upgrade it." % test_type)
                return
            status, code, output, details = run_agent_test(action, test_type, root, cmd.get("source"), effective_source_roots(cfg))
        log.info("action '%s' finished: %s", name, status)
        report_with_retry(cfg, cmd["id"], status, exit_code=code, output=output, details=details)
    except Exception as e:  # noqa: BLE001
        log.exception("could not finish command %s: %s", cmd.get("id"), e)
        try:  # say so, rather than leave BMS waiting for a result that is never coming
            report_with_retry(cfg, cmd["id"], "failed", output="The agent hit an unexpected error: %s" % e)
        except Exception:  # noqa: BLE001
            pass


def main_loop(cfg, once=False):
    busy = threading.Event()
    while not stop.is_set():
        try:
            cmd = poll(cfg, busy=busy.is_set())
            if cmd:
                busy.set()

                def work(c=cmd):
                    try:
                        execute(cfg, c)
                    finally:
                        busy.clear()
                t = threading.Thread(target=work, daemon=False)
                t.start()
                if once:
                    t.join()
        except ApiError as e:
            log.error("%s", e)
            if e.status == 401:
                log.error("BMS rejected the agent secret. Was the agent removed or re-enrolled in BMS?")
        except (urllib.error.URLError, OSError, ssl.SSLError) as e:
            log.error("cannot reach BMS: %s", e)
        if once:
            return
        # Also while a command runs: each check-in tells BMS the agent is alive and busy.
        stop.wait(cfg["poll_seconds"] + random.uniform(0, 3))


# --- installing as a service --------------------------------------------------------------------

SERVICE_NAME = "bms-agent"   # systemd unit name
TASK_NAME = "BMS Agent"      # Windows scheduled task name


class InstallError(Exception):
    pass


def install_paths():
    """Where an installed agent lives. Linux: /opt and /etc. Windows: C:\\ProgramData\\bms-agent."""
    if os.name == "nt":
        base = os.path.join(os.environ.get("ProgramData", r"C:\ProgramData"), "bms-agent")
        return {"dir": base, "script": os.path.join(base, "bms_agent.py"), "config": os.path.join(base, "config.json")}
    return {"dir": "/opt/bms-agent", "script": "/opt/bms-agent/bms_agent.py", "config": "/etc/bms-agent/config.json"}


def parse_locations(items):
    """['nas-main=/mnt/nas', ...] -> {'nas-main': '/mnt/nas'}. The key is what BMS sends, the path is private to this machine."""
    out = {}
    for item in items or []:
        key, sep, path = item.partition("=")
        key, path = key.strip(), path.strip()
        if not sep or not re.match(r"^[a-z0-9]+(-[a-z0-9]+)*$", key) or not path:
            raise InstallError("--location must look like nas-main=/mnt/nas/backups (a lowercase key, then the folder on this "
                               "machine). Got '%s'." % item)
        out[key] = path
    return out


def build_config(url, token, locations, backup_script, poll_seconds=30, allow_insecure_http=False, source_roots=None, remote_actions=True):
    """The config an install writes. With remote actions on (the default) the backup command is set in BMS afterwards; otherwise edit the file."""
    actions = {}
    if backup_script:
        actions["backup"] = {"type": "command", "command": [backup_script], "timeout_seconds": 8 * 3600}
    if locations:
        actions["restore-test"] = {"type": "restore_test"}
    if not actions and not remote_actions:
        raise InstallError("Give --location KEY=PATH (so restore tests know where backups are) and/or --backup-script PATH "
                           "(so BMS can start your backup). The agent needs at least one thing it can do.")
    cfg = {"bms_url": url.rstrip("/"), "token": token, "poll_seconds": poll_seconds, "actions": actions}
    if remote_actions:
        cfg["remote_actions"] = True
    if locations:
        cfg["locations"] = locations
    if source_roots:
        cfg["source_roots"] = list(source_roots)
    if allow_insecure_http:
        cfg["allow_insecure_http"] = True
    return cfg


def _sd_quote(value):
    return '"%s"' % value.replace("\\", "\\\\").replace('"', '\\"') if re.search(r"\s", value) else value


def build_systemd_unit(python, script, config, user="root"):
    """A systemd service that starts at boot and is restarted if it ever stops."""
    return "\n".join([
        "[Unit]",
        "Description=BMS agent (runs backups and restore tests when BMS asks)",
        "After=network-online.target",
        "Wants=network-online.target",
        "",
        "[Service]",
        "ExecStart=%s %s --config %s" % (_sd_quote(python), _sd_quote(script), _sd_quote(config)),
        "Restart=always",
        "RestartSec=10",
        "User=%s" % user,
        "",
        "[Install]",
        "WantedBy=multi-user.target",
        "",
    ])


def build_task_xml(python, script, config):
    """A Windows scheduled task: runs as SYSTEM at every boot, with no time limit, and is restarted every minute if it stops."""
    from xml.sax.saxutils import escape
    args = '"%s" --config "%s"' % (script, config)
    return """<?xml version="1.0" encoding="UTF-16"?>
<Task version="1.4" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">
  <RegistrationInfo><Description>BMS agent: runs backups and restore tests when BMS asks.</Description></RegistrationInfo>
  <Triggers><BootTrigger><Enabled>true</Enabled></BootTrigger></Triggers>
  <Principals><Principal id="Author"><UserId>S-1-5-18</UserId><RunLevel>HighestAvailable</RunLevel></Principal></Principals>
  <Settings>
    <MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy>
    <DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries>
    <StopIfGoingOnBatteries>false</StopIfGoingOnBatteries>
    <AllowHardTerminate>true</AllowHardTerminate>
    <StartWhenAvailable>true</StartWhenAvailable>
    <RunOnlyIfNetworkAvailable>false</RunOnlyIfNetworkAvailable>
    <AllowStartOnDemand>true</AllowStartOnDemand>
    <Enabled>true</Enabled>
    <Hidden>false</Hidden>
    <ExecutionTimeLimit>PT0S</ExecutionTimeLimit>
    <Priority>7</Priority>
    <RestartOnFailure><Interval>PT1M</Interval><Count>999</Count></RestartOnFailure>
  </Settings>
  <Actions Context="Author"><Exec><Command>%s</Command><Arguments>%s</Arguments></Exec></Actions>
</Task>
""" % (escape(python), escape(args))


def _is_admin():
    if os.name == "nt":
        import ctypes
        return bool(ctypes.windll.shell32.IsUserAnAdmin())
    return os.geteuid() == 0


def _run(cmd, check=True):
    try:
        p = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, stdin=subprocess.DEVNULL, timeout=120)
    except (OSError, subprocess.TimeoutExpired) as e:
        raise InstallError("could not run %s: %s" % (cmd[0], e))
    out = p.stdout.decode("utf-8", "replace").strip()
    if check and p.returncode != 0:
        raise InstallError("%s failed (exit %d): %s" % (" ".join(cmd[:2]), p.returncode, out))
    return p.returncode, out


def service_python():
    """The interpreter the service runs with. On Windows, pythonw avoids a console window."""
    exe = sys.executable
    if os.name == "nt":
        w = os.path.join(os.path.dirname(exe), "pythonw.exe")
        if os.path.isfile(w):
            exe = w
    return exe


def resolve_token(args):
    if args.token_stdin:
        return sys.stdin.readline().strip()
    return args.token or os.environ.get("BMS_AGENT_TOKEN", "")


def do_install(args):
    """Copy the agent to its home, write the config, register it as a service that starts at boot, and start it."""
    paths = install_paths()
    token = resolve_token(args)
    if not args.url or not token:
        raise InstallError("Give --url (your BMS address) and the agent secret, as --token, the BMS_AGENT_TOKEN environment "
                           "variable, or --token-stdin. Enroll the agent in BMS to get the secret.")
    cfg = build_config(args.url, token, parse_locations(args.location), args.backup_script, args.poll_seconds, args.allow_insecure_http,
                       [r for r in (args.source_root or []) if r], remote_actions=not args.no_remote_actions)
    try:
        live = validate_config(json.loads(json.dumps(cfg)))
    except ConfigError as e:
        raise InstallError(str(e))
    if args.backup_script and not os.path.isfile(args.backup_script):
        raise InstallError("backup script not found: %s" % args.backup_script)

    python = service_python()
    where = "a systemd service" if os.name != "nt" else "a scheduled task (it runs as SYSTEM at every boot)"
    plan = [
        "copy the agent to %s" % paths["script"],
        "write the config (with the secret, readable only by administrators) to %s" % paths["config"],
        "register %s that restarts the agent if it stops, running %s" % (where, python),
        "start it",
    ]
    if args.dry_run:
        print("Dry run. Nothing is changed. An install would:")
        for step in plan:
            print("  - " + step)
        print("\nConfig that would be written (secret hidden):")
        print(json.dumps(dict(cfg, token="********"), indent=2))
        return 0

    if not _is_admin():
        raise InstallError("run this as %s." % ("root (sudo)" if os.name != "nt" else "Administrator (an elevated PowerShell)"))
    if os.name != "nt" and not shutil.which("systemctl"):
        raise InstallError("this machine does not use systemd. Run the agent with your own supervisor instead, see agent/README.md.")
    if os.name == "nt" and re.search(r"\\Users\\", python, re.I):
        log.warning("Python at %s is inside a user profile, which the SYSTEM account may not be able to run. Install Python for "
                    "all users if the task does not start.", python)

    if not args.skip_check:
        try:
            poll(live)
        except ApiError as e:
            raise InstallError("BMS did not accept this agent: %s" % e)
        except (urllib.error.URLError, OSError, ssl.SSLError) as e:
            raise InstallError("cannot reach BMS at %s: %s (use --skip-check to install anyway)" % (cfg["bms_url"], e))
        log.info("connected to BMS and checked in")

    _stop_service(quiet=True)  # an upgrade: stop the old copy before replacing its files
    os.makedirs(paths["dir"], exist_ok=True)
    shutil.copyfile(os.path.abspath(__file__), paths["script"])
    os.makedirs(os.path.dirname(paths["config"]), exist_ok=True)
    tmp = paths["config"] + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(cfg, f, indent=2)
    _lock_down(tmp, paths["dir"])
    os.replace(tmp, paths["config"])

    if os.name == "nt":
        xml_path = os.path.join(paths["dir"], "task.xml")
        with open(xml_path, "w", encoding="utf-16") as f:
            f.write(build_task_xml(python, paths["script"], paths["config"]))
        _run(["schtasks", "/Create", "/TN", TASK_NAME, "/XML", xml_path, "/F"])
        os.remove(xml_path)
        _run(["schtasks", "/Run", "/TN", TASK_NAME])
        print("Installed. The agent runs as the scheduled task '%s' and starts at every boot." % TASK_NAME)
        print("Check it: schtasks /Query /TN \"%s\"   (and the agent shows Online in BMS within a minute)" % TASK_NAME)
    else:
        unit = "/etc/systemd/system/%s.service" % SERVICE_NAME
        with open(unit, "w", encoding="utf-8") as f:
            f.write(build_systemd_unit(python, paths["script"], paths["config"], args.service_user))
        _run(["systemctl", "daemon-reload"])
        _run(["systemctl", "enable", "--now", SERVICE_NAME])
        time.sleep(2)
        state = _run(["systemctl", "is-active", SERVICE_NAME], check=False)[1]
        print("Installed. systemd service '%s' is %s and starts at every boot." % (SERVICE_NAME, state))
        print("Logs: journalctl -u %s -f" % SERVICE_NAME)
    return 0


def do_upgrade(args):
    """Replace the installed agent program with this file, keeping the existing config and secret, and restart it."""
    paths = install_paths()
    if not os.path.isfile(paths["script"]) or not os.path.isfile(paths["config"]):
        raise InstallError("no installed agent found at %s. Use --install to set one up." % paths["dir"])
    if args.dry_run:
        print("Dry run. An upgrade would stop the service, replace %s with this file (version %s) and start it again. "
              "The config and secret are kept." % (paths["script"], VERSION))
        return 0
    if not _is_admin():
        raise InstallError("run this as %s." % ("root (sudo)" if os.name != "nt" else "Administrator (an elevated PowerShell)"))
    if os.path.abspath(__file__) == os.path.abspath(paths["script"]):
        raise InstallError("this is already the installed copy. Download the new bms_agent.py somewhere else and run --upgrade from there.")
    _stop_service()
    shutil.copyfile(os.path.abspath(__file__), paths["script"])
    if args.enable_remote_actions:
        with open(paths["config"], "r", encoding="utf-8") as f:
            conf = json.load(f)
        conf["remote_actions"] = True
        tmp = paths["config"] + ".tmp"
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(conf, f, indent=2)
        _lock_down(tmp, os.path.dirname(paths["config"]))
        os.replace(tmp, paths["config"])
    if os.name == "nt":
        _run(["schtasks", "/Run", "/TN", TASK_NAME])
    else:
        _run(["systemctl", "restart", SERVICE_NAME])
    print("Upgraded to version %s. The agent has restarted." % VERSION)
    return 0


def _lock_down(config_tmp, directory):
    """The config holds the agent secret: only the owner (root) or Administrators and SYSTEM may read it."""
    if os.name == "nt":
        _run(["icacls", directory, "/inheritance:r", "/grant:r", "*S-1-5-18:(OI)(CI)F", "*S-1-5-32-544:(OI)(CI)F"])
    else:
        os.chmod(config_tmp, 0o600)


def _stop_service(quiet=False):
    if os.name == "nt":
        _run(["schtasks", "/End", "/TN", TASK_NAME], check=False)
    elif shutil.which("systemctl"):
        _run(["systemctl", "stop", SERVICE_NAME], check=False)


def do_uninstall(args):
    paths = install_paths()
    if args.dry_run:
        print("Dry run. An uninstall would stop and remove the service, and delete %s%s." % (
            paths["dir"], "" if args.keep_config else " and the config"))
        return 0
    if not _is_admin():
        raise InstallError("run this as %s." % ("root (sudo)" if os.name != "nt" else "Administrator (an elevated PowerShell)"))
    _stop_service()
    if os.name == "nt":
        _run(["schtasks", "/Delete", "/TN", TASK_NAME, "/F"], check=False)
    else:
        _run(["systemctl", "disable", SERVICE_NAME], check=False)
        unit = "/etc/systemd/system/%s.service" % SERVICE_NAME
        if os.path.exists(unit):
            os.remove(unit)
        _run(["systemctl", "daemon-reload"], check=False)
    if os.path.exists(paths["script"]):
        os.remove(paths["script"])
    if not args.keep_config:
        shutil.rmtree(paths["dir"], ignore_errors=True)
        if os.name != "nt":
            shutil.rmtree(os.path.dirname(paths["config"]), ignore_errors=True)
    print("Uninstalled. Remove the agent in BMS (Servers, then the server) so its secret stops working.")
    return 0


def main():
    ap = argparse.ArgumentParser(description="BMS agent")
    ap.add_argument("--config", default=os.environ.get("BMS_AGENT_CONFIG") or default_config_path())
    ap.add_argument("--once", action="store_true", help="check in once, run a command if one is waiting, then exit")
    ap.add_argument("--check-config", action="store_true", help="validate the config file and exit")
    ap.add_argument("--version", action="version", version=VERSION)
    inst = ap.add_argument_group("install as a service (run as root / Administrator)")
    inst.add_argument("--install", action="store_true", help="install the agent as a service that starts at boot")
    inst.add_argument("--uninstall", action="store_true", help="stop and remove the installed service")
    inst.add_argument("--upgrade", action="store_true", help="replace the installed agent with this file, keeping its config and secret")
    inst.add_argument("--url", help="your BMS address, e.g. https://bms.example.com")
    inst.add_argument("--token", help="the agent secret from BMS (or set BMS_AGENT_TOKEN, or use --token-stdin, to keep it out of the process list)")
    inst.add_argument("--token-stdin", action="store_true", help="read the agent secret from the first line of standard input")
    inst.add_argument("--location", action="append", metavar="KEY=PATH", help="a backup location on this machine, e.g. nas-main=/mnt/nas/backups (repeatable)")
    inst.add_argument("--source-root", action="append", metavar="PATH", help="a folder on this machine whose files may be read to compare backups with their source (repeatable)")
    inst.add_argument("--backup-script", metavar="PATH", help="the program BMS should run to start a backup")
    inst.add_argument("--poll-seconds", type=int, default=30)
    inst.add_argument("--service-user", default="root", help="Linux: the user the service runs as (default root)")
    inst.add_argument("--allow-insecure-http", action="store_true", help="local testing only: allow an http:// BMS address")
    inst.add_argument("--no-remote-actions", action="store_true", help="do not let BMS define backup commands: only the config file decides what runs")
    inst.add_argument("--enable-remote-actions", action="store_true", help="with --upgrade: turn on BMS-managed actions in the existing config")
    inst.add_argument("--skip-check", action="store_true", help="install without first checking in to BMS")
    inst.add_argument("--keep-config", action="store_true", help="with --uninstall: leave the config file in place")
    inst.add_argument("--dry-run", action="store_true", help="show what an install or uninstall would do, and change nothing")
    args = ap.parse_args()
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")

    if args.install or args.uninstall or args.upgrade:
        try:
            return do_install(args) if args.install else do_upgrade(args) if args.upgrade else do_uninstall(args)
        except InstallError as e:
            log.error("%s", e)
            return 2

    try:
        cfg = load_config(args.config)
    except ConfigError as e:
        log.error("%s", e)
        return 2
    if args.check_config:
        log.info("config OK: %d action(s) in the file%s: %s", len(cfg["actions"]), " plus actions managed in BMS" if cfg["remote_actions"] else "", ", ".join(sorted(cfg["actions"])) or "-")
        return 0

    for sig in (signal.SIGINT, signal.SIGTERM):
        signal.signal(sig, lambda *_: stop.set())
    log.info("bms-agent %s starting, polling %s every %ds", VERSION, cfg["bms_url"], cfg["poll_seconds"])
    main_loop(cfg, once=args.once)
    return 0


if __name__ == "__main__":
    sys.exit(main())
