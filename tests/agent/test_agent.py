"""Tests for agent/bms_agent.py (standard library only). Run: python -m unittest discover -s tests/agent"""
import importlib.util
import json
import os
import subprocess
import sys
import tempfile
import unittest
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

    def test_an_install_with_nothing_to_do_is_refused(self):
        with self.assertRaises(agent.InstallError):
            agent.build_config("https://b", "t", {}, None)

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
        code, out = cli("--install", "--dry-run", "--url", "https://b", "--token", "t")
        self.assertEqual(code, 2)
        self.assertIn("at least one thing", out)
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
