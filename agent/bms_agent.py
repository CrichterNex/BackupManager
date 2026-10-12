#!/usr/bin/env python3
"""BMS agent: runs backups and restore tests on this machine when the BMS server asks.

Design rules (they are the point of this file):
  * It only ever calls BMS (outbound HTTPS). Nothing listens on this machine.
  * BMS sends the NAME of an action. What that name runs is decided only by the local config file, so a
    compromised BMS can start the actions you defined here and nothing else.
  * Commands are run as an argument list, never through a shell.
  * Python 3.8+, standard library only. Works the same on Linux and Windows.
"""
import argparse
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

VERSION = "1.2.0"
OUTPUT_TAIL = 4000          # characters of command output sent back to BMS
HTTP_TIMEOUT = 30           # seconds for each call to BMS
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

    actions = cfg.get("actions")
    if not isinstance(actions, dict) or not actions:
        raise ConfigError("config needs an 'actions' object with at least one action")
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


def advertised_actions(cfg):
    return {name: {"type": a["type"]} for name, a in cfg["actions"].items()}


def poll(cfg, busy=False):
    body = {"version": VERSION, "os": "%s %s" % (platform.system(), platform.release()), "actions": advertised_actions(cfg),
            "locations": sorted(cfg["locations"]), "source_roots": cfg["source_roots"]}
    if busy:
        body["busy"] = True
    return api(cfg, "/api/agent/poll", body).get("command")


def report(cfg, command_id, status, exit_code=None, output=None, details=None):
    body = {"status": status}
    if exit_code is not None:
        body["exit_code"] = exit_code
    if output:
        body["output"] = output[-OUTPUT_TAIL:]
    if details:
        body["details"] = details
    api(cfg, "/api/agent/commands/%d/result" % command_id, body)


# --- action: command ----------------------------------------------------------------------------

def run_command(action):
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
                if e.is_symlink():
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


# --- main loop ----------------------------------------------------------------------------------

def restore_path(cfg, action, location, subpath=None):
    """Where to test. BMS names a location by key (and the job's folder inside it); this machine decides the path.
    Returns (path, None) or (None, reason)."""
    if location:
        root = cfg["locations"].get(location)
        if not root:
            return None, "This agent has no path for the location '%s'. Add it under 'locations' in the config file." % location
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
    action = cfg["actions"].get(name)
    expected = "command" if ctype == "backup" else "restore_test"
    try:
        if action is None or action["type"] != expected:
            log.error("BMS asked for '%s' (%s) but no such action is defined here", name, ctype)
            report(cfg, cmd["id"], "failed", output="No action named '%s' of type '%s' is defined in this agent's config." % (name, expected))
            return
        log.info("running %s action '%s' (command %s)", ctype, name, cmd["id"])
        if action["type"] == "command":
            status, code, output, details = run_command(action)
        else:
            root, why = restore_path(cfg, action, cmd.get("location"), cmd.get("subpath"))
            if root is None:
                log.error("%s", why)
                report(cfg, cmd["id"], "failed", output=why)
                return
            status, code, output, details = run_restore_test(action, root, cmd.get("source"), cfg["source_roots"])
        log.info("action '%s' finished: %s", name, status)
        report(cfg, cmd["id"], status, exit_code=code, output=output, details=details)
    except Exception as e:  # noqa: BLE001
        log.exception("could not finish command %s: %s", cmd.get("id"), e)


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


def build_config(url, token, locations, backup_script, poll_seconds=30, allow_insecure_http=False, source_roots=None):
    """The config an install writes. Anything beyond this (more actions, arguments) is edited in the file afterwards."""
    actions = {}
    if backup_script:
        actions["backup"] = {"type": "command", "command": [backup_script], "timeout_seconds": 8 * 3600}
    if locations:
        actions["restore-test"] = {"type": "restore_test"}
    if not actions:
        raise InstallError("Give --location KEY=PATH (so restore tests know where backups are) and/or --backup-script PATH "
                           "(so BMS can start your backup). The agent needs at least one thing it can do.")
    cfg = {"bms_url": url.rstrip("/"), "token": token, "poll_seconds": poll_seconds, "actions": actions}
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
                       [r for r in (args.source_root or []) if r])
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
        log.info("config OK: %d action(s): %s", len(cfg["actions"]), ", ".join(sorted(cfg["actions"])))
        return 0

    for sig in (signal.SIGINT, signal.SIGTERM):
        signal.signal(sig, lambda *_: stop.set())
    log.info("bms-agent %s starting, polling %s every %ds", VERSION, cfg["bms_url"], cfg["poll_seconds"])
    main_loop(cfg, once=args.once)
    return 0


if __name__ == "__main__":
    sys.exit(main())
