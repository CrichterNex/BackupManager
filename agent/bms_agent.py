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

VERSION = "1.0.0"
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


def load_config(path):
    try:
        with open(path, "r", encoding="utf-8") as f:
            cfg = json.load(f)
    except FileNotFoundError:
        raise ConfigError("config file not found: %s" % path)
    except ValueError as e:
        raise ConfigError("config file is not valid JSON: %s" % e)

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
            if not isinstance(a.get("path"), str) or not a["path"]:
                raise ConfigError("action '%s': a restore_test needs a 'path' (the folder holding the backup)" % name)

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
    body = {"version": VERSION, "os": "%s %s" % (platform.system(), platform.release()), "actions": advertised_actions(cfg)}
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


def pick_random_file(root, deadline):
    """One file chosen uniformly at random from a tree, without listing it all in memory. Symlinks are skipped."""
    chosen, seen, stack = None, 0, [root]
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
                    if random.randint(1, seen) == 1:
                        chosen = e.path
            except OSError:
                continue
    return chosen


def fail(failure_type, severity, item, message, **extra):
    d = {"failure_type": failure_type, "severity": severity, "failed_item": item, "error_message": message}
    d.update(extra)
    return "failed", None, message, d


def run_restore_test(action):
    """Pick a random file in the backup, copy it to a staging folder as a restore, compare SHA-256, clean up."""
    root = action["path"]
    deadline = time.monotonic() + int(action.get("timeout_seconds", 300))
    staging = None
    try:
        if not os.path.isdir(root):
            return fail("file_missing", "high", root, "The backup location does not exist or is not reachable.")
        picked = pick_random_file(root, deadline)
        if picked is None:
            return fail("file_missing", "high", root, "The backup location contains no files to restore.")
        original = sha256(picked, deadline)
        staging = tempfile.mkdtemp(prefix="bms-restore-", dir=action.get("staging_dir") or None)
        restored = os.path.join(staging, "restored")
        shutil.copyfile(picked, restored)
        restored_hash = sha256(restored, deadline)
        if original != restored_hash:
            return fail("hash_mismatch", "critical", picked, "The restored file does not match the backup copy.",
                        hash_algorithm="sha256", hash_original=original, hash_restored=restored_hash)
        return "success", 0, "Restored and verified %s" % picked, {
            "hash_algorithm": "sha256", "hash_original": original, "hash_restored": restored_hash, "failed_item": picked}
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
        status, code, output, details = run_command(action) if action["type"] == "command" else run_restore_test(action)
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


def main():
    ap = argparse.ArgumentParser(description="BMS agent")
    ap.add_argument("--config", default=os.environ.get("BMS_AGENT_CONFIG") or default_config_path())
    ap.add_argument("--once", action="store_true", help="check in once, run a command if one is waiting, then exit")
    ap.add_argument("--check-config", action="store_true", help="validate the config file and exit")
    ap.add_argument("--version", action="version", version=VERSION)
    args = ap.parse_args()
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")

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
