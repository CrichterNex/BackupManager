# BMS agent

A single Python file (`bms_agent.py`, Python 3.8+, standard library only) that runs on each server whose backups BMS
should manage. It lets BMS start a backup and run restore tests **on that machine**, which is the only way to test
backups that BMS cannot read itself.

**Tested:** end to end on Windows with Python 3.12 against a live BMS: manual backup, failing backup, timeout,
a command that outlives several check-ins, restore test pass and fail. The Linux path uses the same code but has
not been run on a Linux machine yet.

## How it stays safe

- **Outbound only.** The agent calls BMS over HTTPS. Nothing listens on the server and no inbound port is opened.
- **Named actions only.** BMS sends the *name* of an action (for example `backup`). What that name runs is set in
  the agent's own config file. BMS cannot send a command line, so a compromised BMS or admin account can start the
  actions you defined and nothing else.
- **No shell.** Commands run as an argument list, so there is no shell injection.
- **One secret per server**, shown once at enrolment and stored in BMS only as a hash. Removing the agent in BMS
  invalidates it at once.

## Set up

1. In BMS open **Servers**, pick the server, click **Enroll agent**, and copy the config it shows (once).
2. On the server, save it as the config file and lock it down:
   - Linux: `/etc/bms-agent/config.json`, then `chmod 600 /etc/bms-agent/config.json`
   - Windows: `C:\ProgramData\bms-agent\config.json` (restrict the folder to Administrators and SYSTEM)
3. Edit the actions. Example:

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
         "type": "restore_test",
         "path": "/backups/web-01"
       }
     }
   }
   ```

   On Windows use `["powershell.exe", "-NoProfile", "-File", "C:\\Scripts\\backup.ps1"]` and a path like
   `"D:\\Backups"`.

   - `command` actions run what you list and succeed when it exits with 0. Output (the last 4000 characters) is
     sent back and shown on failures.
   - `restore_test` actions pick a random file under `path`, copy it to a temporary folder as a restore, compare
     SHA-256 of both, and delete the copy. Use the folder that holds the backup copy.
   - Name them `backup` and `restore-test` and BMS picks them automatically. Otherwise it uses the only action of
     that type.

4. Check it, then try it once:

   ```
   python3 bms_agent.py --check-config
   python3 bms_agent.py --once
   ```

5. Run it all the time.

   **Linux (systemd)**, `/etc/systemd/system/bms-agent.service`:

   ```ini
   [Unit]
   Description=BMS agent
   After=network-online.target

   [Service]
   ExecStart=/usr/bin/python3 /opt/bms-agent/bms_agent.py
   Restart=always
   User=root

   [Install]
   WantedBy=multi-user.target
   ```

   then `systemctl enable --now bms-agent`. Use a less privileged `User=` if your backup script allows it.

   **Windows**, run once in an elevated PowerShell:

   ```powershell
   schtasks /Create /TN "BMS Agent" /SC ONSTART /RU SYSTEM /RL HIGHEST /TR "python C:\bms-agent\bms_agent.py"
   schtasks /Run /TN "BMS Agent"
   ```

   For automatic restart on failure, wrap it with a service manager such as NSSM instead.

## What you will see in BMS

- **Servers**: the agent shows Online or Offline, its version and the actions it reports.
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
- On Windows, a timed-out command's child processes are not always killed. Put a timeout inside your own script too.
