"""Tests for agent/bms_agent.py (standard library only). Run: python -m unittest discover -s tests/agent"""
import importlib.util
import json
import os
import subprocess
import stat
import shutil
import sys
import tempfile
import time
import unittest
import urllib.error
import xml.etree.ElementTree as ET

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
AGENT = os.path.join(ROOT, "agent", "bms_agent.py")

spec = importlib.util.spec_from_file_location("bms_agent", AGENT)
agent = importlib.util.module_from_spec(spec)
spec.loader.exec_module(agent)


def cli(*args, stdin=None, env=None):
    e = dict(os.environ, **(env or {}))
    p = subprocess.run([sys.executable, AGENT, *args], input=stdin, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, env=e, timeout=60)
    return p.returncode, p.stdout.decode("utf-8", "replace")


class ConfigTests(unittest.TestCase):
    def good(self, **over):
        cfg = {"bms_url": "https://bms.example.com/", "token": "bms_x", "actions": {"backup": {"type": "command", "command": ["/bin/true"]}}}
        cfg.update(over)
        return cfg

    def test_valid_config_gets_defaults(self):
        cfg = agent.validate_config(self.good())
        self.assertEqual(cfg["bms_url"], "https://bms.example.com")
        self.assertEqual(cfg["poll_seconds"], 30)
        self.assertTrue(cfg["verify_tls"])
        self.assertEqual(cfg["locations"], {})

    def test_a_restore_test_with_no_locations_is_fine_when_bms_manages_them_and_refused_when_it_does_not(self):
        # What the installer writes when a location is given, after that location has been moved out of the file and into BMS.
        file_only = {"bms_url": "https://bms.example.com", "token": "bms_x", "actions": {"restore-test": {"type": "restore_test"}}, "locations": {}}
        with self.assertRaises(agent.ConfigError) as e:
            agent.validate_config(dict(file_only, actions={"restore-test": {"type": "restore_test"}}))
        self.assertIn("restore_test needs a 'path'", str(e.exception))
        managed = agent.validate_config(dict(file_only, remote_actions=True, actions={"restore-test": {"type": "restore_test"}}))
        self.assertEqual(managed["locations"], {})
        self.assertEqual(sorted(agent.effective_locations(dict(managed, _managed_locations={"vault": "/mnt/vault"}))), ["vault"])

    def test_a_restore_test_with_a_path_or_with_locations_in_the_file_is_still_accepted(self):
        agent.validate_config(self.good(actions={"restore-test": {"type": "restore_test", "path": "/mnt/backups"}}))
        agent.validate_config(self.good(actions={"restore-test": {"type": "restore_test"}}, locations={"nas": "/mnt/nas"}))

    def test_http_is_refused_unless_allowed_for_testing(self):
        with self.assertRaises(agent.ConfigError):
            agent.validate_config(self.good(bms_url="http://bms.example.com"))
        agent.validate_config(self.good(bms_url="http://localhost:3000", allow_insecure_http=True))

    def test_commands_must_be_argument_lists_never_shell_strings(self):
        with self.assertRaises(agent.ConfigError):
            agent.validate_config(self.good(actions={"backup": {"type": "command", "command": "rm -rf /"}}))

    def test_restore_test_needs_a_path_or_locations(self):
        with self.assertRaises(agent.ConfigError):
            agent.validate_config(self.good(actions={"t": {"type": "restore_test"}}))
        agent.validate_config(self.good(actions={"t": {"type": "restore_test"}}, locations={"nas": "/mnt/nas"}))
        agent.validate_config(self.good(actions={"t": {"type": "restore_test", "path": "/b"}}))

    def test_locations_must_map_keys_to_paths(self):
        with self.assertRaises(agent.ConfigError):
            agent.validate_config(self.good(locations={"nas": ""}))


class RestorePathTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="bmsagent-")
        os.makedirs(os.path.join(self.tmp, "nas", "web-01", "daily"))
        os.makedirs(os.path.join(self.tmp, "secret"))
        self.cfg = {"locations": {"nas": os.path.join(self.tmp, "nas")}}

    def test_key_alone_gives_the_mapped_root(self):
        self.assertEqual(agent.restore_path(self.cfg, {}, "nas")[0], os.path.join(self.tmp, "nas"))

    def test_a_subpath_is_joined_under_the_root(self):
        path, why = agent.restore_path(self.cfg, {}, "nas", "web-01/daily")
        self.assertIsNone(why)
        self.assertEqual(path, os.path.join(self.tmp, "nas", "web-01", "daily"))

    def test_an_unknown_key_is_refused_with_a_reason(self):
        path, why = agent.restore_path(self.cfg, {}, "other")
        self.assertIsNone(path)
        self.assertIn("no path for the location 'other'", why)

    def test_unsafe_subpaths_are_refused(self):
        for bad in ["../secret", "a/../../secret", "/abs", "C:", "a//b", "./a"]:
            path, why = agent.restore_path(self.cfg, {}, "nas", bad)
            self.assertIsNone(path, bad)
            self.assertIn("invalid destination folder", why)

    def test_a_link_pointing_outside_the_location_is_not_followed(self):
        link = os.path.join(self.tmp, "nas", "escape")
        try:
            os.symlink(os.path.join(self.tmp, "secret"), link, target_is_directory=True)
        except (OSError, NotImplementedError):
            self.skipTest("this account cannot create symlinks")
        path, why = agent.restore_path(self.cfg, {}, "nas", "escape")
        self.assertIsNone(path)
        self.assertIn("outside the location", why)

    def test_without_a_location_the_action_path_is_used(self):
        self.assertEqual(agent.restore_path(self.cfg, {"path": "/x"}, None)[0], "/x")
        self.assertIsNone(agent.restore_path(self.cfg, {}, None)[0])


class RestoreTestTests(unittest.TestCase):
    def test_pass_fail_and_cleanup(self):
        root = tempfile.mkdtemp(prefix="bmsagent-")
        staging = tempfile.mkdtemp(prefix="bmsagent-stg-")
        with open(os.path.join(root, "a.txt"), "w") as f:
            f.write("hello")
        status, _, _, d = agent.run_restore_test({"staging_dir": staging}, root)
        self.assertEqual(status, "success")
        self.assertEqual(d["hash_original"], d["hash_restored"])
        self.assertEqual(os.listdir(staging), [], "the staging copy is removed")
        empty = tempfile.mkdtemp(prefix="bmsagent-")
        status, _, _, d = agent.run_restore_test({"staging_dir": staging}, empty)
        self.assertEqual((status, d["failure_type"]), ("failed", "file_missing"))
        status, _, _, d = agent.run_restore_test({"staging_dir": staging}, os.path.join(root, "nope"))
        self.assertEqual((status, d["failure_type"]), ("failed", "file_missing"))


class SourceComparisonTests(unittest.TestCase):
    """A restore test that also compares the backed-up file with the source file it came from."""

    def setUp(self):
        self.tmp = os.path.realpath(tempfile.mkdtemp(prefix="bmssrc-"))
        self.src = os.path.join(self.tmp, "src")
        self.dst = os.path.join(self.tmp, "dst")
        self.stg = os.path.join(self.tmp, "stg")
        for d in (self.src, self.dst, self.stg):
            os.makedirs(d)
        self.action = {"staging_dir": self.stg}
        self.source = {"layout": "contents", "folders": [self.src], "excluded": []}

    def write(self, base, rel, text, mtime=None):
        path = os.path.join(base, *rel.split("/"))
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, "w") as f:
            f.write(text)
        if mtime is not None:
            os.utime(path, (mtime, mtime))
        return path

    def backed_up(self, rel, text="alpha", when=1_700_000_000):
        """A source file and its backup copy with the same modification time, as rsync and robocopy leave them."""
        self.write(self.src, rel, text, when)
        return self.write(self.dst, rel, text, when)

    def run_test(self, source=None, roots=None):
        return agent.run_restore_test(self.action, self.dst, source if source is not None else self.source, [self.src] if roots is None else roots)

    def test_an_unchanged_source_that_matches_is_verified(self):
        self.backed_up("a.txt")
        status, _, _, d = self.run_test()
        self.assertEqual(status, "success")
        self.assertEqual(d["source_status"], "verified")
        self.assertEqual(d["hash_original"], d["hash_restored"])
        self.assertIn("a.txt", d["source_note"])

    def test_a_backup_that_differs_from_an_unchanged_source_is_a_critical_failure(self):
        self.backed_up("a.txt", "alpha")
        self.write(self.dst, "a.txt", "alphX", 1_700_000_100)  # corrupted, and newer than the source
        status, _, _, d = self.run_test()
        self.assertEqual(status, "failed")
        self.assertEqual((d["failure_type"], d["severity"], d["source_status"]), ("hash_mismatch", "critical", "mismatch"))
        self.assertNotEqual(d["hash_original"], d["hash_restored"], "original is the source, restored is the backup copy")

    def test_a_source_edited_after_the_backup_is_not_a_failure(self):
        self.backed_up("a.txt", "alpha")
        self.write(self.src, "a.txt", "edited later", 1_700_000_500)
        status, _, _, d = self.run_test()
        self.assertEqual(status, "success", "the backup copy itself is still intact")
        self.assertEqual(d["source_status"], "not_comparable")
        self.assertIn("changed since the backup", d["source_note"])

    def test_a_source_that_no_longer_exists_is_not_comparable(self):
        self.backed_up("a.txt")
        os.remove(os.path.join(self.src, "a.txt"))
        status, _, _, d = self.run_test()
        self.assertEqual(status, "success")
        self.assertIn("no longer exist", d["source_note"])

    def test_it_tries_other_random_files_until_one_can_be_compared(self):
        for i in range(5):
            self.backed_up("f%d.txt" % i, "data%d" % i)
        for i in range(4):  # four of the five sources have since been edited
            self.write(self.src, "f%d.txt" % i, "edited", 1_700_000_900)
        for _ in range(5):
            status, _, _, d = self.run_test()
            self.assertEqual((status, d["source_status"]), ("success", "verified"), "the one unchanged file is always found")
            self.assertTrue(d["failed_item"].endswith("f4.txt"))

    def test_sources_outside_the_allowed_folders_are_never_read(self):
        self.backed_up("a.txt")
        other = os.path.join(self.tmp, "other")
        os.makedirs(other)
        status, _, _, d = self.run_test(roots=[other])
        self.assertEqual(status, "success")
        self.assertEqual(d["source_status"], "not_comparable")
        self.assertIn("outside the allowed source folders", d["source_note"])

    def test_with_no_source_roots_configured_it_says_so_and_reads_no_source(self):
        self.backed_up("a.txt")
        status, _, _, d = self.run_test(roots=[])
        self.assertEqual(status, "success")
        self.assertEqual(d["source_status"], "not_comparable")
        self.assertIn("source_roots", d["source_note"])

    def test_a_job_without_a_layout_gets_the_plain_integrity_test(self):
        self.backed_up("a.txt")
        status, _, _, d = self.run_test(source={})
        self.assertEqual(status, "success")
        self.assertNotIn("source_status", d)

    def test_a_backup_file_inside_an_excluded_folder_is_skipped(self):
        self.backed_up("sub1/x.txt")
        source = dict(self.source, excluded=[os.path.join(self.src, "sub1")])
        status, _, _, d = self.run_test(source=source)
        self.assertEqual(status, "success")
        self.assertIn("excluded", d["source_note"])

    def test_one_subfolder_per_source_folder(self):
        docs, pics = os.path.join(self.src, "docs"), os.path.join(self.src, "pics")
        self.write(docs, "a.txt", "alpha", 1_700_000_000)
        self.write(self.dst, "docs/a.txt", "alpha", 1_700_000_000)
        source = {"layout": "by_folder_name", "folders": [docs, pics], "excluded": []}
        status, _, _, d = self.run_test(source=source)
        self.assertEqual((status, d["source_status"]), ("success", "verified"))

    def test_the_original_path_recreated_in_the_destination(self):
        self.write(self.src, "a.txt", "alpha", 1_700_000_000)
        real = os.path.join(self.src, "a.txt")
        if os.name == "nt":
            parts = [real[0].upper()] + real[3:].split(os.sep)
        else:
            parts = real.lstrip(os.sep).split(os.sep)
        self.write(self.dst, "/".join(parts), "alpha", 1_700_000_000)
        status, _, _, d = self.run_test(source={"layout": "full_path", "folders": [], "excluded": []})
        self.assertEqual((status, d["source_status"]), ("success", "verified"))

    def test_mapping_rules(self):
        m = agent.map_to_source
        self.assertEqual(m(["a", "b.txt"], "contents", ["/srv/x"])[0], os.path.join("/srv/x", "a", "b.txt"))
        self.assertIsNone(m(["a"], "contents", ["/srv/x", "/srv/y"])[0], "ambiguous with two folders")
        self.assertEqual(m(["docs", "a.txt"], "by_folder_name", ["/srv/docs", "/srv/pics"])[0], os.path.join("/srv/docs", "a.txt"))
        self.assertIsNone(m(["a.txt"], "by_folder_name", ["/srv/docs"])[0], "not inside a per-folder sub-folder")
        self.assertIsNone(m(["x"], "by_folder_name", ["/srv/docs"])[0])
        self.assertIsNone(m(["a"], "nonsense", [])[0])

    def test_folder_containment_is_a_real_boundary(self):
        self.assertTrue(agent.inside(os.path.join(self.tmp, "a", "b"), os.path.join(self.tmp, "a")))
        self.assertFalse(agent.inside(os.path.join(self.tmp, "a2"), os.path.join(self.tmp, "a")), "a name prefix is not inside")
        self.assertTrue(agent.inside(os.path.join(self.tmp, "a"), os.path.join(self.tmp, "a")))

    def test_source_roots_must_be_a_list_of_folders(self):
        base = {"bms_url": "https://b", "token": "t", "actions": {"b": {"type": "command", "command": ["/x"]}}}
        self.assertEqual(agent.validate_config(dict(base))["source_roots"], [])
        self.assertEqual(agent.validate_config(dict(base, source_roots=["/srv"]))["source_roots"], ["/srv"])
        for bad in ("/srv", [""], [1]):
            with self.assertRaises(agent.ConfigError):
                agent.validate_config(dict(base, source_roots=bad))

    def test_the_installer_can_set_source_roots(self):
        cfg = agent.build_config("https://b", "t", {"nas": "/mnt"}, None, source_roots=["/srv/www", "D:\\Data"])
        self.assertEqual(cfg["source_roots"], ["/srv/www", "D:\\Data"])
        agent.validate_config(json.loads(json.dumps(cfg)))
        self.assertNotIn("source_roots", agent.build_config("https://b", "t", {"nas": "/mnt"}, None))


class InstallerTests(unittest.TestCase):
    def test_locations_are_parsed_and_validated(self):
        self.assertEqual(agent.parse_locations(["nas-main=/mnt/nas", "usb=E:\\Backups"]), {"nas-main": "/mnt/nas", "usb": "E:\\Backups"})
        for bad in ["nas", "NAS=/x", "nas=", "=/x", "a b=/x"]:
            with self.assertRaises(agent.InstallError, msg=bad):
                agent.parse_locations([bad])

    def test_the_installed_config_validates_and_only_names_what_it_was_given(self):
        cfg = agent.build_config("https://bms.example.com", "bms_t", {"nas": "/mnt/nas"}, "/usr/local/bin/backup.sh")
        agent.validate_config(json.loads(json.dumps(cfg)))
        self.assertEqual(set(cfg["actions"]), {"backup", "restore-test"})
        self.assertEqual(cfg["actions"]["backup"]["command"], ["/usr/local/bin/backup.sh"])
        only_tests = agent.build_config("https://b", "t", {"nas": "/mnt/nas"}, None)
        self.assertEqual(set(only_tests["actions"]), {"restore-test"})
        only_backup = agent.build_config("https://b", "t", {}, "/b.sh")
        self.assertEqual(set(only_backup["actions"]), {"backup"})
        self.assertNotIn("locations", only_backup)

    def test_an_install_with_nothing_to_do_is_refused_only_when_bms_cannot_supply_actions(self):
        with self.assertRaises(agent.InstallError):
            agent.build_config("https://b", "t", {}, None, remote_actions=False)
        cfg = agent.build_config("https://b", "t", {}, None)
        self.assertTrue(cfg["remote_actions"])
        agent.validate_config(json.loads(json.dumps(cfg)))

    def test_remote_actions_are_on_by_default_and_can_be_left_out(self):
        self.assertTrue(agent.build_config("https://b", "t", {"nas": "/mnt"}, None)["remote_actions"])
        self.assertNotIn("remote_actions", agent.build_config("https://b", "t", {"nas": "/mnt"}, None, remote_actions=False))

    def test_the_systemd_unit_restarts_always_and_starts_at_boot(self):
        unit = agent.build_systemd_unit("/usr/bin/python3", "/opt/bms-agent/bms_agent.py", "/etc/bms-agent/config.json")
        self.assertIn("ExecStart=/usr/bin/python3 /opt/bms-agent/bms_agent.py --config /etc/bms-agent/config.json", unit)
        self.assertIn("Restart=always", unit)
        self.assertIn("WantedBy=multi-user.target", unit)
        self.assertIn("After=network-online.target", unit)
        spaced = agent.build_systemd_unit("/usr/bin/python3", "/opt/my agent/bms_agent.py", "/etc/c.json")
        self.assertIn('"/opt/my agent/bms_agent.py"', spaced)

    def test_the_windows_task_is_well_formed_runs_as_system_at_boot_and_restarts(self):
        xml = agent.build_task_xml("C:\\Program Files\\Python312\\pythonw.exe", "C:\\ProgramData\\bms-agent\\bms_agent.py", "C:\\ProgramData\\bms-agent\\config.json")
        ns = {"t": "http://schemas.microsoft.com/windows/2004/02/mit/task"}
        root = ET.fromstring(xml.split("?>", 1)[1])
        self.assertIsNotNone(root.find("t:Triggers/t:BootTrigger", ns))
        self.assertEqual(root.find("t:Principals/t:Principal/t:UserId", ns).text, "S-1-5-18")
        self.assertEqual(root.find("t:Settings/t:RestartOnFailure/t:Count", ns).text, "999")
        self.assertEqual(root.find("t:Settings/t:ExecutionTimeLimit", ns).text, "PT0S")
        self.assertEqual(root.find("t:Actions/t:Exec/t:Command", ns).text, "C:\\Program Files\\Python312\\pythonw.exe")
        self.assertEqual(root.find("t:Actions/t:Exec/t:Arguments", ns).text,
                         '"C:\\ProgramData\\bms-agent\\bms_agent.py" --config "C:\\ProgramData\\bms-agent\\config.json"')

    def test_paths_with_xml_special_characters_cannot_break_the_task(self):
        xml = agent.build_task_xml("C:\\A&B\\python.exe", "C:\\x<y>\\a.py", "C:\\c.json")
        ET.fromstring(xml.split("?>", 1)[1])  # would raise if the XML were broken

    def test_install_paths_are_system_locations(self):
        p = agent.install_paths()
        self.assertTrue(p["script"].startswith(p["dir"]))
        if os.name != "nt":
            self.assertEqual(p["config"], "/etc/bms-agent/config.json")


class InstallCommandTests(unittest.TestCase):
    base = ["--install", "--dry-run", "--url", "https://bms.example.com", "--location", "nas=/mnt/nas"]

    def test_a_dry_run_shows_the_plan_hides_the_secret_and_changes_nothing(self):
        paths = agent.install_paths()
        existed = os.path.exists(paths["dir"])
        code, out = cli(*self.base, env={"BMS_AGENT_TOKEN": "bms_supersecret"})
        self.assertEqual(code, 0, out)
        self.assertIn("Dry run. Nothing is changed", out)
        self.assertIn("register", out)
        self.assertNotIn("bms_supersecret", out)
        self.assertEqual(os.path.exists(paths["dir"]), existed, "a dry run must not create the install folder")

    def test_the_secret_can_come_from_stdin(self):
        code, out = cli(*self.base, "--token-stdin", stdin=b"bms_from_stdin\n")
        self.assertEqual(code, 0, out)
        self.assertNotIn("bms_from_stdin", out)

    def test_missing_pieces_and_plain_http_are_refused_with_a_reason(self):
        self.assertEqual(cli("--install", "--dry-run", "--location", "nas=/x")[0], 2, "no url or secret")
        code, out = cli("--install", "--dry-run", "--url", "https://b", "--token", "t", "--no-remote-actions")
        self.assertEqual(code, 2)
        self.assertIn("at least one thing", out)
        self.assertEqual(cli("--install", "--dry-run", "--url", "https://b", "--token", "t")[0], 0, "BMS can supply the actions")
        code, out = cli("--install", "--dry-run", "--url", "http://b", "--token", "t", "--location", "nas=/x")
        self.assertEqual(code, 2)
        self.assertIn("https://", out)

    def test_a_missing_backup_script_is_caught_before_installing(self):
        code, out = cli("--install", "--dry-run", "--url", "https://b", "--token", "t", "--backup-script", os.path.join(tempfile.gettempdir(), "no-such-script.sh"))
        self.assertEqual(code, 2)
        self.assertIn("not found", out)

    def test_a_real_install_needs_administrator_rights(self):
        if agent._is_admin():
            self.skipTest("running as an administrator: a real install would change this machine")
        code, out = cli("--install", "--skip-check", "--url", "https://b", "--token", "t", "--location", "nas=/x")
        self.assertEqual(code, 2)
        self.assertRegex(out, r"run this as (root|Administrator)")

    def test_upgrade_and_uninstall_dry_runs_change_nothing_and_upgrade_needs_an_install(self):
        code, out = cli("--uninstall", "--dry-run")
        self.assertEqual(code, 0, out)
        self.assertIn("Dry run", out)
        paths = agent.install_paths()
        code, out = cli("--upgrade", "--dry-run")
        if os.path.isfile(paths["script"]):
            self.assertEqual(code, 0, out)
        else:
            self.assertEqual(code, 2, out)
            self.assertIn("no installed agent found", out)

    def test_version_and_help_still_work(self):
        self.assertEqual(cli("--version")[1].strip(), agent.VERSION)
        self.assertIn("--install", cli("--help")[1])


if __name__ == "__main__":
    unittest.main()


class ManagedActionTests(unittest.TestCase):
    def setUp(self):
        # These tests are about managed actions, so keep the machine's own built-in tools out of the way.
        self._orig = agent.builtin_available
        agent.builtin_available = lambda kind: False
        self.addCleanup(lambda: setattr(agent, "builtin_available", self._orig))

    def cfg(self, remote=True, **extra):
        return agent.validate_config({"bms_url": "https://b", "token": "t", "remote_actions": remote, "actions": extra.get("actions", {}), "locations": {"n": "/x"}})

    def test_managed_actions_are_ignored_unless_the_config_allows_them(self):
        c = self.cfg(remote=False, actions={"restore-test": {"type": "restore_test"}})
        c["_managed"] = agent.clean_managed({"backup": {"command": ["/b.sh"]}})
        self.assertEqual(set(agent.effective_actions(c)), {"restore-test"})

    def test_a_managed_command_is_added_and_advertised(self):
        c = self.cfg()
        c["_managed"] = agent.clean_managed({"backup": {"type": "command", "command": ["/b.sh", "--full"], "timeout_seconds": 600, "cwd": "/srv"}})
        a = agent.effective_actions(c)["backup"]
        self.assertEqual((a["command"], a["timeout_seconds"], a["cwd"]), (["/b.sh", "--full"], 600, "/srv"))
        self.assertEqual(agent.advertised_actions(c)["backup"], {"type": "command", "timeout_seconds": 600})

    def test_an_unconfigured_default_never_hides_a_file_action_and_fails_clearly(self):
        c = self.cfg(actions={"backup": {"type": "command", "command": ["/file.sh"]}})
        c["_managed"] = agent.clean_managed({"backup": {"command": None}})
        self.assertEqual(agent.effective_actions(c)["backup"]["command"], ["/file.sh"])
        c2 = self.cfg()
        c2["_managed"] = agent.clean_managed({"backup": {"command": None}})
        status, _, out, _ = agent.run_command(agent.effective_actions(c2)["backup"])
        self.assertEqual(status, "failed")
        self.assertIn("no command yet", out)

    def test_a_managed_command_replaces_a_file_command_but_never_a_restore_test(self):
        c = self.cfg(actions={"backup": {"type": "command", "command": ["/old.sh"]}, "restore-test": {"type": "restore_test"}})
        c["_managed"] = agent.clean_managed({"backup": {"command": ["/new.sh"]}, "restore-test": {"command": ["/evil.sh"]}})
        e = agent.effective_actions(c)
        self.assertEqual(e["backup"]["command"], ["/new.sh"])
        self.assertEqual(e["restore-test"]["type"], "restore_test")

    def test_malformed_managed_entries_are_dropped_not_fatal(self):
        bad = {"ok": {"command": ["/b.sh"]}, "has space": {"command": ["/b.sh"]}, "str": {"command": "rm -rf /"},
               "empty-arg": {"command": [""]}, "nums": {"command": [1]}, "t": {"command": ["/b"], "timeout_seconds": 5}, "list": ["x"]}
        out = agent.clean_managed(bad)
        self.assertEqual(set(out), {"ok", "t"})
        self.assertNotIn("timeout_seconds", out["t"], "out of range timeouts fall back to the default")
        self.assertEqual(agent.clean_managed("nope"), {})

    def test_a_command_from_a_managed_action_runs_without_a_shell(self):
        c = self.cfg()
        c["_managed"] = agent.clean_managed({"backup": {"command": [sys.executable, "-c", "print('hi; echo no')"]}})
        status, code, out, _ = agent.run_command(agent.effective_actions(c)["backup"])
        self.assertEqual((status, code), ("success", 0))
        self.assertIn("hi; echo no", out)


def write(path, text):
    with open(path, "w") as f:
        f.write(text)


class BuiltinBackupTests(unittest.TestCase):
    def test_every_layout_puts_files_where_the_source_comparison_looks_for_them(self):
        """backup_target and map_to_source must be exact opposites, or a verified backup would look like a mismatch."""
        if os.name == "nt":
            src, dest = r"C:\Data\Web", r"D:\Backups\web-01"
            includes = [r"C:\Data\Web", r"C:\Data\Mail"]
        else:
            src, dest = "/data/web", "/mnt/b/web-01"
            includes = ["/data/web", "/data/mail"]
        for layout, inc in (("by_folder_name", includes), ("full_path", includes), ("contents", [src])):
            target = agent.backup_target(layout, src, dest)
            rel = os.path.relpath(os.path.join(target, "sub", "f.txt"), dest).split(os.sep)
            back, why = agent.map_to_source(rel, layout, inc)
            self.assertIsNone(why, layout)
            self.assertEqual(os.path.normcase(back), os.path.normcase(os.path.join(src, "sub", "f.txt")), layout)

    def test_a_drive_root_and_an_unknown_layout_are_refused(self):
        with self.assertRaises(ValueError):
            agent.build_backup_command("robocopy", "C:\\", "D:\b", [])
        with self.assertRaises(ValueError):
            agent.backup_target("nonsense", "/a", "/b")

    def test_the_robocopy_command_copies_only_and_excludes_what_is_inside_the_folder(self):
        with tempfile.TemporaryDirectory() as d:
            src = os.path.join(d, "Folder")
            os.makedirs(os.path.join(src, "Sub1"))
            cmd = agent.build_backup_command("robocopy", src, os.path.join(d, "out"), [os.path.join(src, "Sub1"), os.path.join(src, "x.tmp"), os.path.join(d, "Elsewhere")])
        self.assertEqual(cmd[:3], ["robocopy", src, os.path.join(d, "out")])
        for flag in ("/MIR", "/PURGE"):
            self.assertNotIn(flag, cmd, "must never delete at the destination")
        self.assertEqual(cmd[cmd.index("/XD") + 1], os.path.join(src, "Sub1"))
        self.assertEqual(cmd[cmd.index("/XF") + 1], os.path.join(src, "x.tmp"))
        self.assertNotIn(os.path.join(d, "Elsewhere"), cmd)

    def test_the_rsync_command_never_deletes_and_anchors_and_escapes_exclusions(self):
        cmd = agent.build_backup_command("rsync", "/data/web", "/mnt/b/web", ["/data/web/cache", "/data/web/we[ir]d*", "/data/mail"])
        self.assertNotIn("--delete", cmd)
        self.assertIn("--exclude=/cache", cmd)
        self.assertIn(r"--exclude=/we\[ir]d\*", cmd)
        self.assertFalse(any("mail" in c for c in cmd), "exclusions outside the folder are left out")
        self.assertEqual(cmd[-3:], ["--", "/data/web/", "/mnt/b/web/"])
        self.assertEqual(" ".join(cmd).count("--"), len([c for c in cmd if c.startswith("--")]))

    def test_exit_codes_mean_what_each_tool_says(self):
        self.assertTrue(all(agent.backup_ok("robocopy", c) for c in range(0, 8)))
        self.assertFalse(agent.backup_ok("robocopy", 8))
        self.assertTrue(agent.backup_ok("rsync", 0) and agent.backup_ok("rsync", 24))
        self.assertFalse(agent.backup_ok("rsync", 23))

    def cfg(self, **kw):
        c = {"bms_url": "https://b", "token": "t", "actions": {}, "remote_actions": True, "locations": {}, "source_roots": []}
        c.update(kw)
        return agent.validate_config(c)

    @unittest.skipUnless(os.name == "nt" and agent.builtin_available("robocopy"), "needs robocopy on Windows")
    def test_robocopy_really_copies_honours_exclusions_and_deletes_nothing(self):
        with tempfile.TemporaryDirectory() as d:
            src, loc = os.path.join(d, "Folder"), os.path.join(d, "loc")
            for sub in ("Sub1", "Sub2"):
                os.makedirs(os.path.join(src, sub))
                write(os.path.join(src, sub, "f.txt"), sub)
            write(os.path.join(src, "top.txt"), "top")
            os.makedirs(os.path.join(loc, "web-01", "Folder"))
            write(os.path.join(loc, "web-01", "Folder", "old-extra.txt"), "already in the backup")
            c = self.cfg(locations={"nas": loc}, source_roots=[d])
            cmd = {"location": "nas", "subpath": "web-01", "source": {"layout": "by_folder_name", "folders": [src], "excluded": [os.path.join(src, "Sub1")]}}
            status, _, out, _ = agent.run_builtin_backup(c, "robocopy", cmd)
            self.assertEqual(status, "success", out)
            dest = os.path.join(loc, "web-01", "Folder")
            self.assertTrue(os.path.exists(os.path.join(dest, "top.txt")))
            self.assertTrue(os.path.exists(os.path.join(dest, "Sub2", "f.txt")))
            self.assertFalse(os.path.exists(os.path.join(dest, "Sub1")), "excluded folder must not be copied")
            self.assertTrue(os.path.exists(os.path.join(dest, "old-extra.txt")), "copy only: nothing is deleted at the destination")

    def test_a_folder_outside_source_roots_a_missing_folder_and_an_unknown_location_are_refused(self):
        with tempfile.TemporaryDirectory() as d, tempfile.TemporaryDirectory() as other:
            real = agent.builtin_available
            agent.builtin_available = lambda kind: True
            try:
                src = os.path.join(d, "a")
                os.makedirs(src)
                c = self.cfg(locations={"nas": os.path.join(d, "loc")}, source_roots=[d])
                job = lambda f, loc="nas": {"location": loc, "subpath": "x", "source": {"layout": "by_folder_name", "folders": [f], "excluded": []}}
                st, _, out, _ = agent.run_builtin_backup(c, "rsync", job(os.path.join(other, "z")))
                self.assertEqual(st, "failed")
                self.assertIn("source_roots", out)
                st, _, out, _ = agent.run_builtin_backup(c, "rsync", job(os.path.join(d, "missing")))
                self.assertIn("does not exist", out)
                st, _, out, _ = agent.run_builtin_backup(c, "rsync", job(src, "nope"))
                self.assertIn("no path for the location", out)
                st, _, out, _ = agent.run_builtin_backup(c, "rsync", {"location": "nas", "subpath": "../escape", "source": {"layout": "contents", "folders": [src], "excluded": []}})
                self.assertIn("invalid destination", out)
                st, _, out, _ = agent.run_builtin_backup(c, "rsync", {"location": "nas", "source": {}})
                self.assertIn("did not send the folders", out)
            finally:
                agent.builtin_available = real

    def test_the_built_in_tools_are_advertised_only_where_they_exist(self):
        real = agent.builtin_available
        try:
            agent.builtin_available = lambda kind: kind == "rsync"
            self.assertEqual(agent.advertised_actions(self.cfg()), {"rsync": {"type": "rsync", "timeout_seconds": 8 * 3600}})
        finally:
            agent.builtin_available = real


class MountTests(unittest.TestCase):
    """A Linux agent mounts a location written as a share, with the login BMS holds, and then uses the mounted folder."""

    def setUp(self):
        self.root = tempfile.mkdtemp()
        self.addCleanup(shutil.rmtree, self.root, True)
        self.calls = []

    def runner(self, results):
        """A stand-in for subprocess.run that returns the given (returncode, output) in turn and records what it was asked."""
        results = list(results)

        def run(cmd, env=None, **kw):
            self.calls.append((cmd, dict(env or {})))
            rc, out = results.pop(0) if results else (0, b"")
            return subprocess.CompletedProcess(cmd, rc, stdout=out, stderr=None)
        return run

    def mount(self, results=((0, b""),), **over):
        kw = dict(mount_root=self.root, run=self.runner(results), which=lambda name: "/sbin/mount.cifs", is_root=True, tcp_open=lambda host: None)
        kw.update(over)
        return agent.mount_share("vault.example.com", "IT", "svc-backup", "S3cret!pw", **kw)

    def test_share_paths_are_read_in_both_spellings(self):
        self.assertEqual(agent.unc_parts("\\\\vault.example.com\\IT\\TestBkp\\sub"), ("vault.example.com", "IT", ["TestBkp", "sub"]))
        self.assertEqual(agent.unc_parts("//vault.example.com/IT/TestBkp"), ("vault.example.com", "IT", ["TestBkp"]))
        self.assertEqual(agent.unc_parts("//vault.example.com/IT"), ("vault.example.com", "IT", []))
        for not_a_share in ("/mnt/vault/x", "IT/TestBkp", "C:\\Data", "", None, "//onlyhost"):
            self.assertIsNone(agent.unc_parts(not_a_share), repr(not_a_share))

    def test_the_share_is_mounted_privately_with_the_password_only_in_the_environment(self):
        mp, why = self.mount()
        self.assertIsNone(why)
        self.assertEqual(mp, os.path.join(self.root, "vault.example.com_it"))
        self.assertTrue(os.path.isdir(mp))
        cmd, env = self.calls[0]
        self.assertEqual(cmd[:3], ["/sbin/mount.cifs", "//vault.example.com/IT", mp])
        self.assertEqual(env["PASSWD"], "S3cret!pw")
        self.assertNotIn("S3cret!pw", " ".join(cmd), "never on a command line")
        opts = cmd[cmd.index("-o") + 1]
        for wanted in ("username=svc-backup", "vers=3.0", "uid=0", "gid=0", "file_mode=0600", "dir_mode=0700"):
            self.assertIn(wanted, opts)
        if os.name != "nt":
            self.assertEqual(oct(os.stat(self.root).st_mode & 0o777), "0o700", "the folder holding mounts is private")

    def test_older_smb_versions_are_tried_in_turn_until_one_works(self):
        mp, why = self.mount([(1, b"mount error(95): Operation not supported"), (1, b"mount error(22): Invalid argument"), (0, b"")])
        self.assertIsNone(why)
        self.assertEqual([c[0][c[0].index("-o") + 1].split(",")[1] for c in self.calls], ["vers=3.0", "vers=2.1", "vers=2.0"])

    def test_a_share_that_will_not_mount_says_why_and_never_shows_the_password(self):
        mp, why = self.mount([(32, b"mount error(13): Permission denied. S3cret!pw was wrong")] * 4)
        self.assertIsNone(mp)
        self.assertIn("Could not mount", why)
        self.assertIn("svc-backup", why)
        self.assertIn("Permission denied", why)
        self.assertNotIn("S3cret!pw", why)
        self.assertFalse(os.path.exists(os.path.join(self.root, "vault.example.com_it")), "the empty mount folder is removed again")

    def test_a_wrong_password_stops_at_once_and_says_so_instead_of_trying_older_versions(self):
        mp, why = self.mount([(32, b"mount error(13): Permission denied")] * 4)
        self.assertEqual(len(self.calls), 1, "no other SMB version can fix a wrong password")
        self.assertIn("refused the user name or password", why)
        self.assertIn("SMB 3.0: mount error(13)", why)

    def test_when_every_version_is_refused_each_answer_is_shown_with_what_to_check(self):
        mp, why = self.mount([(32, b"mount error(112): Host is down\nRefer to the mount.cifs(8) manual page")] * 4)
        self.assertEqual(len(self.calls), 4)
        for vers in ("3.0", "2.1", "2.0", "1.0"):
            self.assertIn("SMB %s: mount error(112): Host is down" % vers, why)
        self.assertIn("refused every SMB version", why)
        self.assertIn("dmesg", why)
        self.assertNotIn("Refer to the mount.cifs", why, "only the first line of each answer is kept")

    def test_an_unreachable_server_is_a_network_message_and_nothing_is_mounted(self):
        mp, why = self.mount(tcp_open=lambda host: "timed out")
        self.assertIsNone(mp)
        self.assertIn("Cannot reach 'vault.example.com' on port 445", why)
        self.assertIn("firewall", why)
        self.assertEqual(self.calls, [])

    def test_a_domain_in_the_user_name_is_passed_as_a_domain(self):
        agent_user = "SABT\\crichter"
        kw = dict(mount_root=self.root, run=self.runner([(0, b"")]), which=lambda name: "/sbin/mount.cifs", is_root=True, tcp_open=lambda host: None)
        mp, why = agent.mount_share("vault.example.com", "IT", agent_user, "pw", **kw)
        self.assertIsNone(why)
        opts = self.calls[0][0][self.calls[0][0].index("-o") + 1]
        self.assertIn("username=crichter", opts)
        self.assertIn("domain=SABT", opts)
        self.assertNotIn("SABT\\", opts)

    def test_a_missing_share_name_stops_at_once(self):
        mp, why = self.mount([(32, b"mount error(2): No such file or directory")] * 4)
        self.assertEqual(len(self.calls), 1)
        self.assertIn("share name was not found", why)

    def test_an_already_mounted_share_is_reused_without_mounting_again(self):
        mounts = os.path.join(self.root, "mounts")
        mp = os.path.join(self.root, "vault.example.com_it")
        with open(mounts, "w") as f:
            f.write("//vault.example.com/IT %s cifs rw 0 0\n" % mp)
        self.assertTrue(agent.is_mounted(mp, mounts))
        self.assertFalse(agent.is_mounted(mp + "x", mounts))
        orig = agent.is_mounted
        agent.is_mounted = lambda m, mounts_file=mounts: orig(m, mounts_file)
        self.addCleanup(setattr, agent, "is_mounted", orig)
        got, why = self.mount()
        self.assertEqual((got, why), (mp, None))
        self.assertEqual(self.calls, [])

    def test_it_stops_with_a_clear_message_when_it_cannot_mount(self):
        self.assertIn("needs the agent to run as root", self.mount(is_root=False)[1])
        self.assertIn("cifs-utils", self.mount(which=lambda name: None)[1])
        self.assertEqual(self.calls, [])

    def test_names_that_could_smuggle_in_a_mount_option_are_refused(self):
        for host, share in (("bad host", "IT"), ("h;rm", "IT"), ("h", "I,T"), ("h", "a\nb"), ("", "IT")):
            self.assertIsNotNone(agent.mount_share(host, share, "u", "p", mount_root=self.root, run=self.runner([]), which=lambda n: "/sbin/mount.cifs", is_root=True)[1], (host, share))
        for user in ("a,b=c", "u%pw"):
            self.assertIsNotNone(agent.mount_share("h", "IT", user, "p", mount_root=self.root, run=self.runner([]), which=lambda n: "/sbin/mount.cifs", is_root=True)[1], user)
        self.assertEqual(self.calls, [])

    # --- through ensure_location_access, as a Linux machine

    def cfg(self, location="\\\\vault.example.com\\IT\\TestBkp", managed=True, creds=True):
        c = {"locations": {} if managed else {"vault": location}, "remote_actions": True, "_managed_locations": {"vault": location} if managed else {},
             "_credentials": {"vault": {"username": "svc-backup", "password": "S3cret!pw"}} if creds else {}}
        return c

    def as_linux(self, mounted=("/var/x", None)):
        real = agent.os.name
        agent.os.name = "posix"
        self.addCleanup(setattr, agent.os, "name", real)
        seen = []
        orig = agent.mount_share
        agent.mount_share = lambda *a, **k: (seen.append(a), mounted)[1]
        self.addCleanup(setattr, agent, "mount_share", orig)
        return seen

    def test_a_share_location_becomes_the_mounted_folder_below_it(self):
        seen = self.as_linux(("/var/lib/bms-agent/mounts/vault_it", None))
        c = self.cfg()
        self.assertIsNone(agent.ensure_location_access(c, "vault"))
        self.assertEqual(seen, [("vault.example.com", "IT", "svc-backup", "S3cret!pw")])
        self.assertEqual(agent.effective_locations(c)["vault"], os.path.join("/var/lib/bms-agent/mounts/vault_it", "TestBkp"))
        self.assertEqual(agent.declared_locations(c)["vault"], "\\\\vault.example.com\\IT\\TestBkp", "what BMS set is still what it is")

    def test_a_location_that_is_already_a_local_folder_is_left_alone(self):
        seen = self.as_linux()
        c = self.cfg(location="/mnt/vault/TestBkp")
        self.assertIsNone(agent.ensure_location_access(c, "vault"))
        self.assertEqual(seen, [])
        self.assertEqual(agent.effective_locations(c)["vault"], "/mnt/vault/TestBkp")

    def test_without_a_sign_in_for_it_the_message_says_what_to_do_and_nothing_is_mounted(self):
        seen = self.as_linux()
        msg = agent.ensure_location_access(self.cfg(creds=False), "vault")
        self.assertIn("no sign-in for it", msg)
        self.assertIn("mount the share yourself", msg)
        self.assertEqual(seen, [])

    def test_a_mount_that_fails_is_the_failure_message(self):
        self.as_linux((None, "Could not mount '\\\\vault.example.com\\IT' as svc-backup: Permission denied"))
        self.assertIn("Permission denied", agent.ensure_location_access(self.cfg(), "vault"))

    def test_the_config_file_overriding_bms_is_said_out_loud(self):
        c = {"locations": {"vault": "IT/TestBkp"}, "remote_actions": True, "_managed_locations": {"vault": "\\\\vault.example.com\\IT\\TestBkp"}}
        note = agent.config_override_note(c, "vault")
        self.assertIn("config file overrides", note)
        self.assertIn("IT/TestBkp", note)
        self.assertEqual(agent.config_override_note({"locations": {"vault": "/a"}, "remote_actions": True, "_managed_locations": {"vault": "/a"}}, "vault"), "")
        self.assertEqual(agent.config_override_note({"locations": {"vault": "/a"}, "remote_actions": False, "_managed_locations": {"vault": "/b"}}, "vault"), "")
        self.assertEqual(agent.config_override_note({"locations": {}, "remote_actions": True, "_managed_locations": {"vault": "/b"}}, "vault"), "")


class BuiltinRestoreTestTests(unittest.TestCase):
    """The restore test is offered by the agent itself when it has locations to read, like the built-in rsync and robocopy."""

    def cfg(self, **over):
        c = {"bms_url": "https://bms.example.com", "token": "bms_x", "actions": {}, "locations": {}, "remote_actions": False, "poll_seconds": 30, "source_roots": []}
        c.update(over)
        return c

    def test_an_agent_with_bms_managed_locations_offers_a_restore_test_with_nothing_in_its_file(self):
        c = self.cfg(remote_actions=True, _managed_locations={"vault": "/mnt/vault/TestBkp"})
        acts = agent.effective_actions(c)
        self.assertEqual(acts["restore-test"], {"type": "restore_test"})
        adv = agent.advertised_actions(c)["restore-test"]
        self.assertEqual(adv["type"], "restore_test")
        self.assertIn("random_restore", adv["tests"])

    def test_an_agent_with_locations_in_its_file_offers_one_too(self):
        self.assertIn("restore-test", agent.effective_actions(self.cfg(locations={"nas": "/mnt/nas"})))

    def test_an_agent_with_no_locations_anywhere_does_not_offer_one(self):
        self.assertNotIn("restore-test", agent.effective_actions(self.cfg()))
        self.assertNotIn("restore-test", agent.effective_actions(self.cfg(remote_actions=True)), "until BMS has given it a location to read")

    def test_one_defined_in_the_file_is_never_replaced_or_duplicated(self):
        mine = {"type": "restore_test", "path": "/mnt/mine", "timeout_seconds": 99}
        acts = agent.effective_actions(self.cfg(remote_actions=True, locations={"nas": "/mnt/nas"}, actions={"nightly-restore": mine}))
        self.assertEqual([n for n, a in acts.items() if a["type"] == "restore_test"], ["nightly-restore"], "the file's own is the only one")
        self.assertEqual(acts["nightly-restore"], mine)

    def test_a_config_with_no_actions_and_remote_actions_on_is_valid_and_gets_one_once_bms_sends_a_location(self):
        cfg = agent.validate_config({"bms_url": "https://bms.example.com", "token": "bms_x", "remote_actions": True})
        self.assertNotIn("restore-test", agent.effective_actions(cfg))
        cfg["_managed_locations"] = {"vault": "/mnt/vault"}
        self.assertIn("restore-test", agent.effective_actions(cfg))


class SmbDestinationTests(unittest.TestCase):
    """rsync to a mounted SMB share: the share cannot take owners, permissions or symbolic links, so the backup is made without them."""

    MOUNTS = "\n".join([
        "/dev/sda1 / ext4 rw,relatime 0 0",
        "//vault.example/IT /mnt/vault cifs rw,vers=3.0 0 0",
        "//vault.example/Big\\040Share /mnt/big\\040share smb3 rw 0 0",
        "/dev/sdb1 /mnt/vault/local-disk ext4 rw 0 0",
        "tmpfs /run tmpfs rw 0 0",
    ]) + "\n"

    def mounts(self):
        f = tempfile.NamedTemporaryFile("w", suffix=".mounts", delete=False)
        f.write(self.MOUNTS)
        f.close()
        self.addCleanup(os.unlink, f.name)
        return f.name

    @unittest.skipIf(os.name == "nt", "reads Linux mount tables")
    def test_the_file_system_is_the_one_of_the_longest_mount_that_holds_the_path(self):
        m = self.mounts()
        self.assertEqual(agent.filesystem_type("/mnt/vault/TestBkp/x", m), "cifs")
        self.assertEqual(agent.filesystem_type("/mnt/vault", m), "cifs")
        self.assertEqual(agent.filesystem_type("/mnt/vault/local-disk/data", m), "ext4", "a disk mounted inside the share is not the share")
        self.assertEqual(agent.filesystem_type("/mnt/big share/backups", m), "smb3", "a space in a mount point is written as \\040 in /proc/mounts")
        self.assertEqual(agent.filesystem_type("/home/u", m), "ext4")
        self.assertEqual(agent.filesystem_type("/mnt/vaultx/oops", m), "ext4", "a folder that merely starts with the same letters is not inside the mount")

    @unittest.skipIf(os.name == "nt", "reads Linux mount tables")
    def test_a_path_whose_mounts_cannot_be_read_is_not_guessed_at(self):
        self.assertIsNone(agent.filesystem_type("/mnt/vault", os.path.join(tempfile.gettempdir(), "no-such-mounts-file")))

    def test_rsync_to_an_smb_share_leaves_out_what_the_share_cannot_hold(self):
        for fs in ("cifs", "smb3"):
            cmd = agent.build_backup_command("rsync", "/data/web", "/mnt/vault/web", ["/data/web/cache"], fs)
            for wanted in ("-rt", "--no-perms", "--no-owner", "--no-group", "--modify-window=2", "--partial", "--exclude=/cache"):
                self.assertIn(wanted, cmd, fs)
            self.assertNotIn("-a", cmd, "archive mode would try to set owners, permissions and links")
            self.assertNotIn("--delete", cmd)
            self.assertEqual(cmd[-3:], ["--", "/data/web/", "/mnt/vault/web/"])

    def test_every_other_destination_is_still_archive_mode(self):
        for fs in (None, "ext4", "nfs4", "xfs"):
            cmd = agent.build_backup_command("rsync", "/data/web", "/mnt/b/web", [], fs)
            self.assertEqual(cmd[:3], ["rsync", "-a", "--partial"], fs)
            self.assertNotIn("--no-perms", cmd)

    def test_robocopy_is_not_changed_by_the_file_system(self):
        self.assertEqual(agent.build_backup_command("robocopy", "C:\\Data", "D:\\B", [], "cifs")[:2], ["robocopy", "C:\\Data"])

    @unittest.skipIf(os.name == "nt", "the hint is for Linux machines")
    def test_a_location_that_is_really_an_smb_path_says_to_mount_it(self):
        for path in ("IT/TestBkp", "//vault.example/IT/TestBkp", "smb://vault.example/IT", "\\\\vault.example\\IT"):
            msg = agent.folder_problem(path, "the backup location folder")
            self.assertIn("mount", msg, path)
            self.assertIn("mount -t cifs", msg, path)
            self.assertIn("Windows only", msg)

    @unittest.skipIf(os.name == "nt", "the hint is for Linux machines")
    def test_an_ordinary_missing_absolute_folder_does_not_get_the_mount_advice(self):
        self.assertNotIn("mount -t cifs", agent.folder_problem("/mnt/definitely/not/here", "the backup location folder"))


class ManagedLocationTests(unittest.TestCase):
    def cfg(self, remote=True, locations=None):
        return agent.validate_config({"bms_url": "https://b", "token": "t", "remote_actions": remote, "actions": {} if remote else {"b": {"type": "command", "command": ["x"]}}, "locations": locations or {}})

    def test_a_location_set_in_bms_is_used_and_advertised_only_when_remote_actions_are_on(self):
        on, off = self.cfg(), self.cfg(remote=False, locations={"a": "/x"})
        for c in (on, off):
            c["_managed_locations"] = agent.clean_managed_locations({"cnt": os.path.abspath(os.sep + "mnt")})
        self.assertIn("cnt", agent.effective_locations(on))
        self.assertNotIn("cnt", agent.effective_locations(off))
        path, why = agent.restore_path(on, {}, "cnt", None)
        self.assertEqual((path, why), (os.path.abspath(os.sep + "mnt"), None))

    def test_the_config_file_wins_over_bms_for_the_same_key(self):
        c = self.cfg(locations={"cnt": "/from-file"})
        c["_managed_locations"] = {"cnt": "/from-bms", "other": "/o"}
        self.assertEqual(agent.effective_locations(c), {"cnt": "/from-file", "other": "/o"})

    def test_malformed_managed_locations_are_dropped(self):
        b = chr(92)
        out = agent.clean_managed_locations({"ok": os.path.abspath(os.sep + "m"), "Bad Key": "/x", "rel": "rel/x", "unc": b * 2 + "nas" + b + "s", "nul": "/x" + chr(0), "n": 5})
        self.assertEqual(set(out), {"ok", "unc"} if os.name == "nt" else {"ok", "unc"})
        self.assertEqual(agent.clean_managed_locations("nope"), {})


class ManagedRootTests(unittest.TestCase):
    def cfg(self, remote=True, roots=None):
        return agent.validate_config({"bms_url": "https://b", "token": "t", "remote_actions": remote, "source_roots": roots or [],
                                      "actions": {} if remote else {"b": {"type": "command", "command": ["x"]}}})

    def test_roots_from_bms_are_added_to_the_files_only_when_remote_actions_are_on(self):
        here = os.path.abspath(os.sep + "srv")
        on, off = self.cfg(roots=[os.path.abspath(os.sep + "file")]), self.cfg(remote=False)
        for c in (on, off):
            c["_managed_roots"] = agent.clean_managed_roots([here, here])
        self.assertEqual(agent.effective_source_roots(on), [os.path.abspath(os.sep + "file"), here])
        self.assertEqual(agent.effective_source_roots(off), [])

    def test_malformed_roots_are_dropped(self):
        ok = os.path.abspath(os.sep + "ok")
        self.assertEqual(agent.clean_managed_roots([ok, "rel/x", ok + "/../etc", 5, "", ok]), [ok])
        self.assertEqual(agent.clean_managed_roots("nope"), [])

    def test_a_folder_allowed_in_bms_lets_a_backup_read_it(self):
        with tempfile.TemporaryDirectory() as d:
            src = os.path.join(d, "a")
            os.makedirs(src)
            c = self.cfg()
            real = agent.builtin_available
            agent.builtin_available = lambda kind: True
            try:
                cmd = {"location": "x", "subpath": "y", "source": {"layout": "contents", "folders": [src], "excluded": []}}
                st, _, out, _ = agent.run_builtin_backup(c, "rsync", cmd)
                self.assertIn("source_roots", out)
                c["_managed_roots"] = [d]
                st, _, out, _ = agent.run_builtin_backup(c, "rsync", cmd)
                self.assertNotIn("source_roots", out, "now past the folder check (it stops later, on the unknown location)")
                self.assertIn("no path for the location", out)
            finally:
                agent.builtin_available = real


class LocationFolderTests(unittest.TestCase):
    def cfg(self, loc, roots):
        return agent.validate_config({"bms_url": "https://b", "token": "t", "remote_actions": True, "actions": {}, "locations": {"nas": loc}, "source_roots": roots})

    def run_backup(self, c, src):
        real = agent.builtin_available
        agent.builtin_available = lambda kind: True
        try:
            return agent.run_builtin_backup(c, "rsync", {"location": "nas", "subpath": "web-01/daily", "source": {"layout": "contents", "folders": [src], "excluded": []}})
        finally:
            agent.builtin_available = real

    def test_a_missing_location_folder_is_reported_not_created(self):
        with tempfile.TemporaryDirectory() as d:
            src, missing = os.path.join(d, "src"), os.path.join(d, "share", "TestBkp")
            os.makedirs(src)
            status, _, out, _ = self.run_backup(self.cfg(missing, [d]), src)
            self.assertEqual(status, "failed")
            self.assertIn("does not exist", out)
            self.assertIn(missing, out)
            self.assertFalse(os.path.exists(os.path.join(d, "share")), "the agent must not invent a location's folder")

    def test_access_denied_on_a_share_explains_the_service_account(self):
        real = os.stat
        unc = chr(92) * 2 + "vault" + chr(92) + "IT" + chr(92) + "TestBkp"
        def deny(path, *a, **k):
            if str(path) == unc:
                raise PermissionError(5, "Access is denied")
            return real(path, *a, **k)
        os.stat = deny
        try:
            msg = agent.folder_problem(unc, "the backup location folder")
        finally:
            os.stat = real
        self.assertIn("Access denied", msg)
        if os.name == "nt":
            self.assertIn("COMPUTERNAME$", msg)
            self.assertIn(agent.run_as(), msg)

    def test_a_file_where_a_folder_should_be_is_reported(self):
        with tempfile.TemporaryDirectory() as d:
            f = os.path.join(d, "afile")
            write(f, "x")
            self.assertIn("is not a folder", agent.folder_problem(f, "the location"))
            self.assertIsNone(agent.folder_problem(d, "the location"))

    def test_the_jobs_own_folders_are_created_below_an_existing_location(self):
        with tempfile.TemporaryDirectory() as d:
            src, loc = os.path.join(d, "src"), os.path.join(d, "loc")
            os.makedirs(src)
            os.makedirs(loc)
            real_run = agent.subprocess.run
            agent.subprocess.run = lambda *a, **k: type("P", (), {"returncode": 0, "stdout": b"ok"})()
            try:
                status, _, out, _ = self.run_backup(self.cfg(loc, [d]), src)
            finally:
                agent.subprocess.run = real_run
            self.assertEqual(status, "success", out)
            self.assertTrue(os.path.isdir(os.path.join(loc, "web-01", "daily")))


class CredentialTests(unittest.TestCase):
    UNC = chr(92) * 2 + "vault" + chr(92) + "IT" + chr(92) + "TestBkp"

    def cfg(self, remote=True):
        c = agent.validate_config({"bms_url": "https://b", "token": "t", "remote_actions": remote, "locations": {"vault": self.UNC},
                                   "actions": {} if remote else {"b": {"type": "command", "command": ["x"]}}})
        c["_credentials"] = agent.clean_managed_credentials({"vault": {"username": "CORP" + chr(92) + "svc", "password": "S3cret!"}})
        return c

    def patch(self, rc_sequence):
        calls = []
        real_nt, real_conn = agent.os.name, agent._wnet_connect
        agent.os.name = "nt"

        def fake(share, user, pw):
            calls.append((share, user, pw))
            return rc_sequence.pop(0) if rc_sequence else 0
        agent._wnet_connect = fake

        def undo():
            agent.os.name, agent._wnet_connect = real_nt, real_conn
        self.addCleanup(undo)
        return calls

    def test_it_signs_in_to_the_share_root_with_the_stored_account(self):
        calls = self.patch([0])
        self.assertIsNone(agent.ensure_location_access(self.cfg(), "vault"))
        self.assertEqual(calls, [(chr(92) * 2 + "vault" + chr(92) + "IT", "CORP" + chr(92) + "svc", "S3cret!")])

    def test_a_failed_sign_in_says_why_and_never_contains_the_password(self):
        self.patch([1326])
        msg = agent.ensure_location_access(self.cfg(), "vault")
        self.assertIn("Could not sign in", msg)
        self.assertIn("svc", msg)
        self.assertIn("1326", msg)
        self.assertNotIn("S3cret", msg)

    def test_an_existing_connection_counts_as_success(self):
        self.patch([85])
        self.assertIsNone(agent.ensure_location_access(self.cfg(), "vault"))

    def test_nothing_is_done_without_credentials_for_that_location_off_windows_or_with_remote_actions_off(self):
        calls = self.patch([0])
        c = self.cfg()
        self.assertIsNone(agent.ensure_location_access(c, "other"))
        self.assertIsNone(agent.ensure_location_access(self.cfg(remote=False), "vault"))
        self.assertEqual(calls, [], "no Windows sign-in was attempted for any of them")

    def test_a_local_folder_needs_no_sign_in(self):
        calls = self.patch([0])
        c = self.cfg()
        c["locations"]["vault"] = "D:" + chr(92) + "Backups"
        self.assertIsNone(agent.ensure_location_access(c, "vault"))
        self.assertEqual(calls, [])

    def test_credentials_are_checked_and_malformed_ones_dropped(self):
        out = agent.clean_managed_credentials({"ok": {"username": "u", "password": "p"}, "Bad Key": {"username": "u", "password": "p"},
                                               "nopw": {"username": "u"}, "empty": {"username": "", "password": "p"}, "str": "x"})
        self.assertEqual(set(out), {"ok"})
        self.assertEqual(agent.clean_managed_credentials(None), {})

    def test_the_poll_reports_its_version_and_keeps_new_credentials_only_in_memory(self):
        c = self.cfg()
        c.pop("_credentials")
        sent = []
        real = agent.api

        def fake(cfg, path, body):
            sent.append(body)
            return {"command": None, "managed_credentials": {"vault": {"username": "u", "password": "pw"}}, "credentials_version": 7}
        agent.api = fake
        try:
            agent.poll(c)
            agent.poll(c)
        finally:
            agent.api = real
        self.assertEqual([b["credentials_version"] for b in sent], [0, 7])
        self.assertEqual(c["_credentials"]["vault"]["password"], "pw")
        with tempfile.TemporaryDirectory() as d:
            self.assertEqual(os.listdir(d), [], "nothing is written to disk")


class LongRunningTests(unittest.TestCase):
    def cfg(self, **actions):
        return agent.validate_config({"bms_url": "https://b", "token": "t", "actions": actions or {"b": {"type": "command", "command": ["x"]}}})

    def test_every_action_tells_bms_how_long_it_may_run(self):
        c = self.cfg(slow={"type": "command", "command": ["x"], "timeout_seconds": 86400}, quick={"type": "command", "command": ["y"]}, rt={"type": "restore_test", "path": "/x"})
        a = agent.advertised_actions(c)
        self.assertEqual(a["slow"]["timeout_seconds"], 86400)
        self.assertEqual(a["quick"]["timeout_seconds"], agent.DEFAULT_TIMEOUT)
        self.assertEqual(a["rt"]["timeout_seconds"], 300)

    def patch_report(self, outcomes):
        """Make report() raise or succeed in turn, and never really wait."""
        calls = []
        real_report, real_wait = agent.report, agent.stop.wait

        def fake(cfg, cid, status, **kw):
            calls.append((cid, status))
            o = outcomes.pop(0) if outcomes else None
            if isinstance(o, Exception):
                raise o
        agent.report = fake
        agent.stop.wait = lambda delay: False
        self.addCleanup(lambda: (setattr(agent, "report", real_report), setattr(agent.stop, "wait", real_wait)))
        return calls

    def test_a_result_is_retried_until_bms_accepts_it(self):
        calls = self.patch_report([agent.ApiError(503, "down"), urllib.error.URLError("no route"), ConnectionResetError("x"), None])
        self.assertTrue(agent.report_with_retry({}, 7, "success"))
        self.assertEqual(len(calls), 4)

    def test_a_refusal_is_final_and_not_retried(self):
        calls = self.patch_report([agent.ApiError(409, "This command is already expired.")])
        self.assertFalse(agent.report_with_retry({}, 7, "success"))
        self.assertEqual(len(calls), 1)

    def test_a_busy_or_slow_bms_is_retried_but_a_bad_request_is_not(self):
        calls = self.patch_report([agent.ApiError(429, "slow down"), agent.ApiError(408, "timeout"), None])
        self.assertTrue(agent.report_with_retry({}, 7, "success"))
        self.assertEqual(len(calls), 3)
        calls = self.patch_report([agent.ApiError(400, "bad")])
        self.assertFalse(agent.report_with_retry({}, 8, "success"))
        self.assertEqual(len(calls), 1)

    def test_it_gives_up_after_the_last_try_and_when_asked_to_stop(self):
        calls = self.patch_report([agent.ApiError(500, "x")] * 50)
        self.assertFalse(agent.report_with_retry({}, 7, "success"))
        self.assertEqual(len(calls), 1 + len(agent.REPORT_RETRY_DELAYS))
        self.assertGreaterEqual(sum(agent.REPORT_RETRY_DELAYS), 20 * 60, "keeps trying for a long while")
        self.assertLessEqual(sum(agent.REPORT_RETRY_DELAYS), 29 * 60, "but inside the time BMS waits")
        calls = self.patch_report([agent.ApiError(500, "x")] * 50)
        agent.stop.wait = lambda delay: True
        self.assertFalse(agent.report_with_retry({}, 7, "success"))
        self.assertEqual(len(calls), 1, "stopping the service ends the retrying")

    def test_execute_delivers_its_result_through_the_retrying_path(self):
        calls = self.patch_report([agent.ApiError(502, "bad gateway"), None])
        c = self.cfg(b={"type": "command", "command": [sys.executable, "-c", "print('ok')"]})
        agent.execute(c, {"id": 5, "type": "backup", "action": "b"})
        self.assertEqual(calls, [(5, "success"), (5, "success")])


class MoreTestsTests(unittest.TestCase):
    def action(self, **kw):
        return dict({"type": "restore_test"}, **kw)

    def test_the_agent_says_which_tests_it_can_run_and_how_long_each_may_take(self):
        c = agent.validate_config({"bms_url": "https://b", "token": "t", "actions": {"restore-test": {"type": "restore_test", "full_read_timeout_seconds": 7200}}, "locations": {"n": "/x"}})
        a = agent.advertised_actions(c)["restore-test"]
        self.assertEqual(set(a["tests"]), {"random_restore", "synthetic_file", "full_recovery", "file_permissions"})
        self.assertEqual(a["test_timeouts"]["full_recovery"], 7200)
        self.assertEqual(a["test_timeouts"]["random_restore"], 300)

    def test_write_and_read_back_passes_reports_free_space_and_leaves_nothing_behind(self):
        with tempfile.TemporaryDirectory() as d:
            status, code, out, details = agent.run_synthetic_file(self.action(), d)
            self.assertEqual(status, "success", out)
            self.assertEqual(details["hash_original"], details["hash_restored"])
            self.assertIn("256.0 KB", details["summary"])
            self.assertIn("free_bytes", details)
            self.assertEqual(os.listdir(d), [], "the hidden test folder is removed")

    def test_write_and_read_back_fails_when_the_location_cannot_be_written_or_is_missing(self):
        with tempfile.TemporaryDirectory() as d:
            status, _, out, details = agent.run_synthetic_file(self.action(), os.path.join(d, "nope"))
            self.assertEqual((status, details["failure_type"]), ("failed", "file_missing"))
            real = os.mkdir

            def deny(path, *a, **k):
                raise PermissionError(13, "Access is denied", path)
            os.mkdir = deny
            try:
                status, _, out, details = agent.run_synthetic_file(self.action(), d)
            finally:
                os.mkdir = real
            self.assertEqual((status, details["failure_type"]), ("failed", "access_denied"))
            self.assertIn("write access", out)

    def test_write_and_read_back_detects_data_that_does_not_come_back_the_same(self):
        with tempfile.TemporaryDirectory() as d:
            real = open

            def corrupt(path, mode="r", *a, **k):
                f = real(path, mode, *a, **k)
                if "rb" in mode and os.path.basename(str(path)) == "check.bin":
                    f.close()
                    import io
                    return io.BytesIO(b"x" * agent.SYNTHETIC_BYTES)
                return f
            import builtins
            builtins.open = corrupt
            try:
                status, _, out, details = agent.run_synthetic_file(self.action(), d)
            finally:
                builtins.open = real
            self.assertEqual((status, details["failure_type"], details["severity"]), ("failed", "hash_mismatch", "critical"))

    def test_full_read_reads_every_file_and_ignores_the_hidden_check_folder(self):
        with tempfile.TemporaryDirectory() as d:
            for rel, text in (("a.txt", "aaa"), ("sub/b.txt", "bbbbb"), ("sub/deep/c.txt", "c")):
                os.makedirs(os.path.dirname(os.path.join(d, rel)), exist_ok=True)
                write(os.path.join(d, rel), text)
            os.makedirs(os.path.join(d, agent.CHECK_PREFIX + "1-ab"))
            write(os.path.join(d, agent.CHECK_PREFIX + "1-ab", "check.bin"), "should not be counted")
            status, _, out, details = agent.run_full_read(self.action(), d)
            self.assertEqual(status, "success", out)
            self.assertEqual((details["files"], details["bytes"]), (3, 9))
            self.assertIn("3 files", details["summary"])

    def test_full_read_fails_on_an_unreadable_file_an_empty_backup_a_missing_folder_and_a_timeout(self):
        with tempfile.TemporaryDirectory() as d:
            write(os.path.join(d, "ok.txt"), "fine")
            write(os.path.join(d, "bad.txt"), "broken")
            real = open
            import builtins

            def flaky(path, mode="r", *a, **k):
                if os.path.basename(str(path)) == "bad.txt":
                    raise OSError(5, "Input/output error", str(path))
                return real(path, mode, *a, **k)
            builtins.open = flaky
            try:
                status, _, out, details = agent.run_full_read(self.action(), d)
            finally:
                builtins.open = real
            self.assertEqual((status, details["failure_type"]), ("failed", "corruption"))
            self.assertIn("bad.txt", out)
            self.assertEqual(details["files"], 1)
            status, _, _, details = agent.run_full_read(self.action(full_read_timeout_seconds=-1), d)
            self.assertEqual(details["failure_type"], "timeout")
        with tempfile.TemporaryDirectory() as empty:
            self.assertEqual(agent.run_full_read(self.action(), empty)[3]["failure_type"], "file_missing")
            self.assertEqual(agent.run_full_read(self.action(), os.path.join(empty, "nope"))[3]["failure_type"], "file_missing")

    def layout(self, d):
        src, backup = os.path.join(d, "src"), os.path.join(d, "backup")
        os.makedirs(src)
        os.makedirs(backup)
        for n in ("a.txt", "b.txt", "c.txt"):
            write(os.path.join(src, n), n)
            shutil.copy2(os.path.join(src, n), os.path.join(backup, n))
        return src, backup, {"layout": "contents", "folders": [src], "excluded": []}

    def test_permissions_match_when_a_backup_keeps_them(self):
        with tempfile.TemporaryDirectory() as d:
            src, backup, source = self.layout(d)
            status, _, out, details = agent.run_permission_check(self.action(), backup, source, [d])
            self.assertEqual(status, "success", out)
            self.assertNotIn("skip_reason", details)
            self.assertIn("Permissions match on 3", details["summary"])

    def test_permissions_that_differ_are_reported_with_both_values(self):
        with tempfile.TemporaryDirectory() as d:
            src, backup, source = self.layout(d)
            names = ("a.txt", "b.txt", "c.txt")
            try:
                for n in names:
                    os.chmod(os.path.join(src, n), stat.S_IREAD)
                    if os.name != "nt":
                        os.chmod(os.path.join(backup, n), 0o666)
                status, _, out, details = agent.run_permission_check(self.action(), backup, source, [d])
            finally:
                for n in names:
                    os.chmod(os.path.join(src, n), stat.S_IWRITE | stat.S_IREAD)
            self.assertEqual((status, details["failure_type"], details["severity"]), ("failed", "restore_failed", "medium"))
            self.assertIn("Permissions differ on 3 of 3", out)
            self.assertIn("source has", out)

    def test_permissions_check_is_skipped_not_passed_when_it_cannot_compare(self):
        with tempfile.TemporaryDirectory() as d:
            src, backup, source = self.layout(d)
            for args, why in (((backup, None, [d]), "laid out"), ((backup, source, []), "source roots"), ((backup, source, [os.path.join(d, "elsewhere")]), "No sampled backup file")):
                status, code, out, details = agent.run_permission_check(self.action(), *args)
                self.assertEqual((status, code), ("success", 0))
                self.assertIn(why, details["skip_reason"])
                self.assertNotIn("summary", details, "a skip carries no pass summary")

    def test_an_unknown_test_type_is_refused_not_run_as_a_random_restore(self):
        sent = []
        real = agent.report
        agent.report = lambda cfg, cid, status, **kw: sent.append((status, kw))
        try:
            c = agent.validate_config({"bms_url": "https://b", "token": "t", "actions": {"restore-test": {"type": "restore_test", "path": "/x"}}})
            agent.execute(c, {"id": 3, "type": "restore_test", "test_type": "database_integrity", "action": "restore-test", "location": None})
        finally:
            agent.report = real
        self.assertEqual(sent[0][0], "failed")
        self.assertIn("cannot run the database_integrity test", sent[0][1]["output"])

    def test_the_hidden_check_folder_is_never_picked_as_a_random_restore_file(self):
        with tempfile.TemporaryDirectory() as d:
            write(os.path.join(d, "real.txt"), "x")
            os.makedirs(os.path.join(d, agent.CHECK_PREFIX + "9-zz"))
            write(os.path.join(d, agent.CHECK_PREFIX + "9-zz", "check.bin"), "y")
            picks = {os.path.basename(agent.pick_random_file(d, time.monotonic() + 10)) for _ in range(30)}
            self.assertEqual(picks, {"real.txt"})


class DispatchTests(unittest.TestCase):
    def run_cmd(self, test_type, d, **extra):
        sent = []
        real = agent.report
        agent.report = lambda cfg, cid, status, **kw: sent.append((cid, status, kw))
        try:
            c = agent.validate_config({"bms_url": "https://b", "token": "t", "locations": {"n": d}, "source_roots": [d],
                                       "actions": {"restore-test": {"type": "restore_test"}}})
            agent.execute(c, dict({"id": 9, "type": "restore_test", "test_type": test_type, "action": "restore-test", "location": "n", "subpath": None, "source": None}, **extra))
        finally:
            agent.report = real
        return sent

    def test_a_command_reaches_the_right_test_and_its_summary_is_reported(self):
        with tempfile.TemporaryDirectory() as d:
            write(os.path.join(d, "a.txt"), "hello")
            full = self.run_cmd("full_recovery", d)
            self.assertEqual((full[0][0], full[0][1]), (9, "success"))
            self.assertIn("1 files", full[0][2]["details"]["summary"])
            write_test = self.run_cmd("synthetic_file", d)
            self.assertEqual(write_test[0][1], "success")
            self.assertIn("Wrote and read back", write_test[0][2]["details"]["summary"])
            self.assertEqual([n for n in os.listdir(d)], ["a.txt"], "the write test left nothing behind")
            rand = self.run_cmd("random_restore", d)
            self.assertEqual(rand[0][1], "success")
            self.assertEqual(self.run_cmd(None, d)[0][1], "success", "a command from an older BMS, with no test type, is a random restore")

    def test_a_permissions_command_with_no_layout_reports_a_skip_not_a_pass(self):
        with tempfile.TemporaryDirectory() as d:
            write(os.path.join(d, "a.txt"), "hello")
            sent = self.run_cmd("file_permissions", d)
            self.assertEqual(sent[0][1], "success")
            self.assertIn("laid out", sent[0][2]["details"]["skip_reason"])
            self.assertNotIn("summary", sent[0][2]["details"])
