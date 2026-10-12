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
- **Named actions only.** BMS sends the *name* of an action (for example `backup`). What that name runs is set in
  the agent's own config file. BMS cannot send a command line, so a compromised BMS or admin account can start the
  actions you defined and nothing else.
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
| `--backup-script PATH` | The program BMS runs when it starts a backup here. Add arguments or more actions by editing the config afterwards. |
| `--token-stdin` | Read the secret from standard input instead of the environment. |
| `--service-user NAME` | Linux: run as this user instead of root, if your backup script allows it. |
| `--dry-run` | Show what would happen, hide the secret, change nothing. **Use this first.** |
| `--skip-check` | Install without first checking in to BMS (for example when this server cannot reach BMS yet). |

You must give at least one of `--location` or `--backup-script`, because an agent with nothing it can do is useless.
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
