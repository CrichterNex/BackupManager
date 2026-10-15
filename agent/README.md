# BMS agent

A single Python file (`bms_agent.py`, Python 3.8+, standard library only) that runs on each server whose backups BMS
should manage. It lets BMS start a backup and run restore tests **on that machine**, which is the only way to test
backups that BMS cannot read itself.

**Tested:** end to end on Windows with Python 3.12 against a live BMS: manual backup, failing backup, timeout,
a command that outlives several check-ins, restore test pass and fail, and the install command's dry run and
connection check. **Not yet tested:** a real install on a Linux machine (systemd), and a real install on Windows
(registering the scheduled task needs Administrator, and was only checked as a dry run and by unit tests). Treat
your first install on each as a test, and use `--dry-run` first.

## How it stays safe

- **Outbound only.** The agent calls BMS over HTTPS. Nothing listens on the server and no inbound port is opened.
- **Named actions only.** BMS sends the *name* of an action (for example `backup`), never a command line. What a name
  runs is set in the agent's config file, or, with remote actions on (the default), by an admin on the Agents page.
- **Remote actions are a deliberate trade.** They let an admin change a backup command without touching the server, but
  they also mean anyone who controls BMS or a company admin account can make that server run any command, as root or
  SYSTEM. Only company admins can edit them, every change is in the audit log with before and after, and the agent
  re-checks each entry (command type only, plain argument list, sane limits). If you want the config file to be the only
  authority, install with `--no-remote-actions`. A `restore_test` action in the file can never be replaced from BMS.
- **No shell.** Commands run as an argument list, so there is no shell injection.
- **One secret per server**, shown once at enrolment and stored in BMS only as a hash. Removing the agent in BMS
  invalidates it at once.

## Install it as a service (recommended)

1. In BMS open **Agents**, find the server under *Servers without an agent*, and click **Enroll and get install
   command**. (The same button is on each server's page.) BMS shows two commands, one for Linux and one for Windows,
   with the secret filled in. The secret is shown once.
2. Run the one for your system **as root or Administrator**, on that server. Fill in the folder for each location:

   ```bash
   # Linux (needs Python 3 and systemd)
   curl -fsSL https://bms.example.com/agent/bms_agent.py -o bms_agent.py
   sudo BMS_AGENT_TOKEN='bms_...' python3 bms_agent.py --install --url https://bms.example.com \
        --location nas-main=/mnt/nas/backups --backup-script /usr/local/bin/nightly-backup.sh
   ```

   ```powershell
   # Windows (elevated PowerShell, Python 3 installed for all users)
   Invoke-WebRequest https://bms.example.com/agent/bms_agent.py -OutFile bms_agent.py
   $env:BMS_AGENT_TOKEN='bms_...'; python bms_agent.py --install --url https://bms.example.com `
        --location nas-main=D:\Backups --backup-script C:\Scripts\backup.cmd
   ```

   The installer checks the address and secret by checking in to BMS first, then:
   - copies the agent to `/opt/bms-agent` (Linux) or `C:\ProgramData\bms-agent` (Windows),
   - writes the config (with the secret) where only root, or Administrators and SYSTEM, can read it,
   - registers it so it **starts at boot and is restarted if it ever stops**, and starts it. On Linux that is a
     **systemd service** named `bms-agent`. On Windows it is a **scheduled task** named `BMS Agent` that runs as
     SYSTEM at every boot with no time limit and restarts every minute if it stops. (It is a startup task and not a
     native Windows service, because a plain Python script cannot talk to the Service Control Manager without extra
     software. If you need a native service, wrap the agent with a tool such as NSSM.)
3. In BMS the agent appears **Online** on the Agents page within a minute.

What the install options mean:

| Option | Meaning |
|---|---|
| `--location KEY=PATH` | A backup location on this machine (repeatable). `KEY` is the key you gave the location in BMS. This lets restore tests run here. |
| `--source-root PATH` | A folder on this machine whose files may be read to compare backups with their source (repeatable). Without any, the agent never reads source files. |
| `--backup-script PATH` | Fixes the backup command in the config file. Not needed with remote actions: every agent starts with an empty `backup` action, and you set its command under Agents, Actions. |
| `--no-remote-actions` | Do not let BMS define commands. Only the config file decides what runs. |
| `--enable-remote-actions` | With `--upgrade`: turn remote actions on in an existing install (agents older than 1.3 do not have it). |
| `--token-stdin` | Read the secret from standard input instead of the environment. |
| `--service-user NAME` | Linux: run as this user instead of root, if your backup script allows it. |
| `--dry-run` | Show what would happen, hide the secret, change nothing. **Use this first.** |
| `--skip-check` | Install without first checking in to BMS (for example when this server cannot reach BMS yet). |

With `--no-remote-actions` you must give at least one of `--location` or `--backup-script`, because an agent with nothing it can do is useless.

### Built-in robocopy and rsync (no folders in a script)
For a job whose method is **robocopy** (Windows agent) or **rsync** (Linux agent), the agent builds the command itself from
the job: its folders, exclusions, layout, location and destination folder. You set the folders once, on the job.
- **Copy only.** Robocopy runs `/E /COPY:DAT /DCOPY:DAT /R:2 /W:5` and rsync runs `-a --partial`. Neither deletes at the
  destination, so a wrong source folder cannot wipe good backups. The cost is that files deleted at the source stay in
  the backup until you clean up, so it grows.
- **Exclusions** become `/XD` and `/XF` for robocopy and anchored `--exclude` rules for rsync. Only those inside a source folder apply.
- **Needs on the job:** specific folders (not whole server), a layout, and a location the agent knows (`--location`). A
  job missing one is refused with what to fix. Robocopy cannot take a drive root such as `C:\`, so list folders on it.
- **The location's own folder must already exist.** The agent creates the job's folders below it, never the location folder
  itself, so a mistyped path cannot start a backup somewhere unintended. If it is missing or cannot be opened, the run
  fails and says which, including the account the agent runs as.
- **Network shares on Windows.** The agent runs as SYSTEM, which has no network login: a share sees it as this computer's
  account (`DOMAIN\COMPUTERNAME$`). Give that account Modify rights on the share and folder, or run the BMS Agent task as
  an account that already has access.
- **Allowlists still apply.** Sources must be under the agent's `--source-root` folders, and the destination under its
  location. BMS sends names and folders, never a command line; the program is always robocopy or rsync.
- **Exit codes:** robocopy 0 to 7 is success. rsync 0 is success, and 24 (files vanished while running) is treated as success.
- If a job has the built-in, it is used instead of the agent's `backup` command. Pick the command explicitly (the `action` option) to override it.
- Agents older than 1.4 do not have it and keep running their `backup` command.

### Changing a backup command without reinstalling
The same dialog also holds **Locations on this server**: which folder each BMS backup location key means here, so you do
not need `--location` at install time. Only existing BMS locations can be mapped, the path must be a full path, and a
location set in the agent's config file wins over one set in BMS. Locations are only where backups are written and read,
but a path you map is somewhere the agent will write as root or SYSTEM, so it is audited like an action.

It also holds **Sign-in for network shares**: a user name and password the agent uses to open a location that is a Windows
share, so you do not have to grant rights to the computer account. Read this before using it:
- The password is encrypted in BMS (with `APP_KEY`), never shown or returned again, and kept out of the audit log (which
  records who set it and for which location). It is sent to the agent over HTTPS only when it changes, and the agent keeps
  it in memory only: never on disk, never in the log, never on a command line (it signs in through the operating system).
- Anyone who controls BMS, or has the database and `APP_KEY`, can obtain it. Use a dedicated account that can read and write
  the backup share and nothing else, not a domain admin.
- Windows shares only. A Linux location is a mounted folder, so its login belongs in the mount (fstab or a credentials file).
- Needs agent 1.7 or later, and is used for backups and restore tests on that location.

It also holds **Folders it may read**: the allowlist a robocopy or rsync job's source folders must sit under (subfolders
included), and the only place source comparison reads. This replaces `--source-root` at install time, is audited, and is
added to any `source_roots` already in the config file. Needs agent 1.6 or later.
On the Agents page, an admin clicks **Edit** next to an agent's actions. The program goes on the first line and each
argument on its own line (no shell, so no quoting). The agent picks the change up on its next check-in, within about a
minute. A command in the file is replaced by one of the same name set in BMS; the empty default never hides one. Do not
put passwords in arguments, because they are stored in BMS and appear in the audit log. For an agent installed before
1.3: run the new `bms_agent.py` with `--upgrade --enable-remote-actions` once.
For a BMS on plain `http://` (local testing only) add `--allow-insecure-http`.

**Update** an agent: download the new `bms_agent.py` onto the server and run `python bms_agent.py --upgrade`. It replaces
the program, keeps the config and secret, and restarts the service. The Agents page marks agents running an older
version than the file BMS serves.

**Remove** one: `python bms_agent.py --uninstall` (add `--keep-config` to leave the config), then remove the agent in
BMS so its secret stops working.

Check it: Linux `systemctl status bms-agent` and `journalctl -u bms-agent -f`; Windows `schtasks /Query /TN "BMS Agent"`.

## Set it up by hand

If you prefer not to use the installer:

1. In BMS open **Servers**, pick the server, click **Enroll agent**, and use the config shown ("Or set it up by
   hand"). Save it as `/etc/bms-agent/config.json` (then `chmod 600`) or `C:\ProgramData\bms-agent\config.json`
   (restrict the folder to Administrators and SYSTEM).
2. Edit the actions. Example:

   ```json
   {
     "bms_url": "https://bms.example.com",
     "token": "bms_...",
     "poll_seconds": 30,
     "actions": {
       "backup": {
         "type": "command",
         "command": ["/usr/local/bin/nightly-backup.sh"],
         "timeout_seconds": 7200
       },
       "restore-test": {
         "type": "restore_test"
       }
     },
     "locations": { "nas-main": "/mnt/nas/backups", "offsite-usb": "E:\\Backups" }
   }
   ```

   On Windows use `["powershell.exe", "-NoProfile", "-File", "C:\\Scripts\\backup.ps1"]`.

   - `command` actions run what you list and succeed when it exits with 0. Output (the last 4000 characters) is
     sent back and shown on failures.
   - `restore_test` actions pick a random file in the job's location (and its folder inside it), copy it to a
     temporary folder as a restore, compare SHA-256 of both, and delete the copy.
   - Name them `backup` and `restore-test` and BMS picks them automatically. Otherwise it uses the only action of
     that type.
   - **Locations.** In BMS, jobs point at a *backup location* with a short key such as `nas-main`. The `locations`
     map says where that key lives on this machine, and BMS sends only the key (plus the job's relative folder
     inside it). If this machine has no path for the key, the test is skipped with that reason. A `restore_test`
     with its own `path` still works for jobs that have no location.
   - **Source comparison.** When a job says how it is laid out, the restore test also finds the file the backup came
     from and compares them, but only inside folders you list in `source_roots` (this agent never reads anything else):

     ```json
     "source_roots": ["/var/www", "D:\\Data"]
     ```

     It samples up to 10 random backup files, uses the first whose source still exists and has not been modified since
     it was backed up, and reports *verified*, *mismatch* (a critical failure) or *not comparable* (with the reason, and
     the test still passes on copy integrity). A source edited after the backup is never a failure. Run the agent on the
     **source** server, with the backup location mapped there, for this to work.
3. Check it, then try it once: `python3 bms_agent.py --check-config`, then `python3 bms_agent.py --once`.
4. Run it all the time with your own supervisor, for example this systemd unit, then `systemctl enable --now
   bms-agent`:

   ```ini
   [Unit]
   Description=BMS agent
   After=network-online.target

   [Service]
   ExecStart=/usr/bin/python3 /opt/bms-agent/bms_agent.py --config /etc/bms-agent/config.json
   Restart=always
   User=root

   [Install]
   WantedBy=multi-user.target
   ```

## A Linux machine with no Python: the bundle
Python and `bms_agent.py` stay the normal way to run the agent. For a Linux machine that has no Python and cannot install one (a NAS, say), BMS can
also give out the same agent **bundled with its own Python**, built on request. Nothing about how the agent works changes.

**Using one.** If the Agents page shows "No Python on a Linux server?", download the bundle for the server's CPU type, unpack it and run, as root:

```sh
tar xzf bms-agent-1.10.3-linux-x86_64.tar.gz
cd bms-agent-bundle
sudo BMS_AGENT_TOKEN='the-secret' ./bms-agent.sh --install --url https://your-bms --location nas-main=/data/backups
```

`bms-agent.sh` takes the same options as `python3 bms_agent.py` (`--install`, `--upgrade`, `--check-config`, `--once`, `--version` and so on). The
first time it runs as root it copies the bundled Python to `/opt/bms-python` (set `BMS_PYTHON_DIR` to change that), so the service has a permanent
interpreter and the unpacked folder can be deleted. To upgrade, unpack a newer bundle and run `sudo ./bms-agent.sh --upgrade`.

**Making one** (on request, on Linux or in WSL, not on a Windows file system, so links and file modes survive):

1. Download the "install_only" build for the CPU type from <https://github.com/astral-sh/python-build-standalone/releases>, for example
   `cpython-3.11.17+20261013-x86_64-unknown-linux-gnu-install_only.tar.gz`, and check it against that release's `SHA256SUMS`.
   (The one built for the ReadyNAS had SHA-256 `846bf682e2b67393f2affa5e5c0994e446abc3a7cc5bb699890c36346823644c`.)
2. `BMS_BUNDLE_ARCH=x86_64 BMS_PYTHON_SHA256=<that checksum> sh agent/build_bundle.sh <the tarball> <output folder>` makes
   `bms-agent-<agent version>-linux-<x86_64|aarch64>.tar.gz` and its `.sha256`.
3. Copy both into `storage/app/agent-bundles/` on the BMS server (or the folder named by `BMS_AGENT_BUNDLE_DIR`). BMS then offers it on the Agents
   page and at `/agent/bundle/x86_64` (or `aarch64`). The newest version for each CPU type is served. Until a file is there, that address is not a download.

These Python builds need glibc 2.17 or newer, which covers Debian 8 and every later mainstream Linux. The bundle is about 46 MB.

**Netgear ReadyNAS OS 6 (Debian 8, x86_64).** A bundle was built for it. Not tested on a real ReadyNAS, so before relying on it:
- The installer registers a **systemd** service and stops with a message if `systemctl` is missing. If the NAS has no systemd, install nothing with
  `--install`; run `/opt/bms-python/bin/python3 /opt/bms-agent/bms_agent.py --config /etc/bms-agent/config.json` under whatever starts services on it
  (an init script, or `@reboot` in cron), after writing that config as described above.
- A ReadyNAS firmware update may replace the operating system's folders (`/opt`, `/etc`), which would remove the agent and its config. Check after the
  first update, and run the install again if it has gone.
- The built-in rsync backup needs `rsync` on the NAS, which ReadyNAS has.

## Backing up to an SMB (Windows) share from Linux
**Agent 1.10 and later mount the share for you.** Give the location as the share (`\\vault.example.com\IT\TestBkp`) and enter the share's user name and
password on the Agents page (Edit). The agent then mounts `//vault.example.com/IT` with `mount -t cifs` (SMB 3.0, then 2.1, 2.0 and 1.0 until one works)
under `/var/lib/bms-agent/mounts` (private to root), uses the `TestBkp` folder inside it, and leaves it mounted until the machine restarts. The
password goes to `mount.cifs` through its environment, never on a command line or into a file. It needs the agent to run as root and `mount.cifs` (the
`cifs-utils` package) on the machine; if either is missing the failure says so.

**Restore tests are built in (agent 1.10.3 and later).** Like the built-in rsync and robocopy, the agent offers a `restore-test` action itself as soon as it
has a location to read, from its config file or from BMS. It no longer depends on the installer having been given `--location`, so an agent installed
without one can still run restore tests once a location is set on the Agents page. A `restore_test` action defined in the config file is used instead of
the built-in one.

**When the mount fails**, the failure lists what each SMB version answered and says what it means. A wrong user name or password, a share name the
server does not know, and a server that refuses the connection stop at once, because no other SMB version cures them. `mount error(112): Host is down`
on every version, with the server answering on port 445, means the server refused every SMB dialect this machine can speak: an older kernel (such as
the ReadyNAS's 4.4) supports up to SMB 3.0, so check whether the server requires SMB 3.1.1 only, encryption or signing, and run `dmesg | tail` on the
machine, where the `CIFS VFS` line gives the reason. `DOMAIN\user` is passed to the mount as a domain and a user.

**The agent's config file overrides BMS.** If the config file (`/etc/bms-agent/config.json`) sets the same location key under `"locations"`, that is
used and the one set in BMS is ignored. A failure now says so when the two differ. Remove the key from the file to use the BMS setting.

**Or mount it yourself.** Older agents, or a machine without `cifs-utils` in the usual place, need the share mounted first and the location set to the
mounted folder. The share password entered in BMS is not used then. For example, as root on the server:

```sh
mkdir -p /mnt/vault
printf 'username=BACKUPUSER\npassword=THE-PASSWORD\ndomain=YOURDOMAIN\n' > /root/.smbcred && chmod 600 /root/.smbcred
mount -t cifs //vault.example.com/IT /mnt/vault -o credentials=/root/.smbcred,vers=3.0
mkdir -p /mnt/vault/TestBkp        # the location's own folder: the agent never creates it
```

Then give the location on the Agents page (Edit) as the mounted folder, `/mnt/vault/TestBkp`, not `//vault.example.com/IT/TestBkp` and not
`IT/TestBkp`. To mount it at every boot, add it to `/etc/fstab` (or the server's own start-up script). `mount.cifs` comes from the `cifs-utils`
package, which the server needs.

**What rsync does on a share (agent 1.9.1 and later).** A mounted share cannot hold Linux owners, permissions or symbolic links, and `rsync -a`
reports each one as an error and exits 23, which fails the backup. When the destination is on a `cifs`/`smb3` mount the agent therefore runs
`rsync -rt --no-perms --no-owner --no-group --modify-window=2 --partial`: folders and files and their times are copied; owners, permissions and
symbolic links are not (a link is skipped, not an error). That is a limit of SMB, not of the agent. Restoring from such a backup gives files
owned by whoever mounted the share, so for a backup where ownership matters use a Linux file system or NFS.

If the location is not a folder the agent can see, the failure now says so and shows the mount command.

## Long-running backups
- **Time limit.** Each action tells BMS how long it may run (a custom command's `timeout_seconds`, 8 hours for the built-in
  robocopy and rsync, a restore test's own limit). BMS gives it that long **from when it starts**, plus 30 minutes, then
  marks it failed. Older agents that do not say get 8 hours from the start.
- **Results are not lost.** If BMS is unreachable when a command finishes, the agent keeps trying to deliver the result for
  about half an hour. A refusal (for example the command has already expired) is final.
- **A restart is noticed.** If the agent checks in idle while BMS thinks it is running something, BMS fails that command
  straight away ("the agent restarted or lost this") instead of waiting for the deadline. A command that has just started
  gets a minute of grace.
- **Tests wait their turn.** An agent does one thing at a time. A test queued behind a long backup is held, not failed, for as
  long as that backup is still within its own time. If nothing is ahead of it, a test the agent never picks up still expires.
- **No progress meter.** BMS shows *running* until the result arrives, and only the last 4000 characters of output are kept.

## What you will see in BMS

- **Agents**: every agent at a glance: Online or Offline, last seen, version (with *Update available*), the actions
  and locations it reports, and commands waiting. Servers without an agent are listed with an enrol button.
- **Servers**: the same status on each server's page.
- **Backups**: **Run now** starts a backup through the agent. Jobs with a cron schedule are started on time
  automatically while the agent is online. Cron times are in UTC.
- **Tests**: restore tests for an agent server run on the agent, weekly and on demand.
- If the agent is offline and never picks a command up, BMS marks the backup or test failed after a deadline
  (8 hours for a backup, 2 hours for a test) and tells you why.

## Limits worth knowing

- A restore test proves the backup files can be found, read and copied intact. It does not prove a vendor tool can
  restore them.
- BMS trusts what the agent reports. Protect the config file, because anyone holding the secret can report results
  for that server.
- The install commands download the agent from your BMS over HTTPS and run it with elevated rights, so they are only as
  trustworthy as your BMS and its certificate. The Agents page shows the file's SHA-256 so you can compare it after
  downloading (`sha256sum bms_agent.py` or `Get-FileHash bms_agent.py`).
- On Windows, a timed-out command's child processes are not always killed. Put a timeout inside your own script too.
- On Windows the service Python must be usable by the SYSTEM account, so install Python for all users and not just
  your own profile.
