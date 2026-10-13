# Backup Management System (BMS)

Multi-tenant platform for tracking backups across companies and proving they can be restored. One Laravel app
serves both the JSON API (`/api`) and the web UI (`/`). There is no separate frontend project.

Built from the blueprint package (v3.0, single company per user). It was first written in TypeScript and
moved to Laravel 13 on PHP 8.3 and MariaDB. The old Node version is in git history (commit `532bff5` and earlier).

## Run it (XAMPP)

1. Start MySQL (MariaDB) in XAMPP and create the database and user:

   ```sql
   CREATE DATABASE bms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE DATABASE bms_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'bms'@'localhost' IDENTIFIED BY 'choose-a-password';
   GRANT ALL ON bms.* TO 'bms'@'localhost';
   GRANT ALL ON bms_test.* TO 'bms'@'localhost';
   ```

2. Set up the app (PHP and Composer on your PATH):

   ```bash
   composer install
   cp .env.example .env          # set DB_PASSWORD
   php artisan key:generate
   php artisan migrate
   php artisan bms:company-create --name "TechCorp Inc" --slug techcorp-inc --admin alice --email alice@techcorp.example
   php artisan serve --port=3000   # http://localhost:3000
   ```

3. Start the test engine in a second terminal: `php artisan schedule:work`. In production use one cron entry,
   `* * * * * php /path/to/artisan schedule:run`.

`php artisan test` runs the suite against the `bms_test` database. It is wiped by every run, so never point it at
real data.

## What exists

- Companies, users, servers, backup jobs, test executions, test failures, notifications, audit trail
- Login (hashed passwords, 8-hour HttpOnly token cookie, login rate limit, 1000 requests/hour per user)
- Roles: company admin, manager (read-only apart from reports), technician (assigned servers only)
- Web UI: dashboard, servers, backups, tests and failure resolution, reports, users, audit log, notifications,
  and a Companies page for platform admins
- The sidebar is grouped: Dashboard; Protection (Backups, Tests, Reports); Infrastructure (Servers, Agents, Locations, Integrations);
  Administration (Users, Audit log, admins only); Platform (Companies, People, platform admins only); and Account (profile,
  notifications, log out) at the bottom. Groups a person cannot use are left out.
- Every page updates itself every 15 seconds, so statuses move without a reload. It waits while a dialog is open, a field
  has been edited, a section is expanded, text is selected or the tab is hidden, and a small "Live · updated" note in the
  corner shows it is working. Reads have their own allowance (7200 an hour, `BMS_READ_RATE_LIMIT_PER_HOUR`), separate from the 1000 an hour for changes, so open tabs never block someone from making a change.

## Platform admins

Company admins are limited to their own company. A separate **platform admin** flag, set by an operator, gives
selected company admins two extra abilities from **Companies** in the UI (`/api/platform/companies`):

- **Create companies.** Creating one also creates its first admin. If you leave the password empty, a temporary
  one is generated and shown once.
- **Work inside any company.** By default a platform admin sees only their own company, like everyone else.
  **Open** on a company switches into it as an admin (a banner shows it, and **Back to my company** leaves).
  This is deliberate and visible, not silent:
  - Entering writes `platform_access_started` to **that company's** audit log, and every change made inside is
    logged there with the platform admin's username and `via_platform_admin`, so its own admins can see it.
  - The switch is a header (`X-Acting-Company`) the UI sends per browser tab. Anyone who is not a platform admin
    sending it gets 403, and an unknown company gets 404. It is never ignored.

### Users, and access to other companies

- **Editing people.** On **Users**, a company admin can edit a person's name, email, phone and role, reset their
  password, and disable or enable them. Admins can edit their own details but not their own role or status, so a
  company cannot lock itself out.
- **Access to other companies.** On **People** (platform admins only), a platform admin can give any person access to
  specific other companies, with a **role for each** (admin, manager or technician). The person keeps their home
  company and signs in there. A **Company** picker in the sidebar switches them into a company they have access to,
  and while inside they act with the granted role, not their home role, and nothing more. Other companies' admins see
  them listed as "access granted by a platform admin" (name and role only) and can assign a granted technician to
  servers, but cannot edit them. The role applies to one request at a time and is never written to the account.
- **Auditing.** Granting, changing and removing access, and each time someone enters a company, are written to that
  company's own audit log, and changes made through a grant are marked `via_access_grant`.
- **Only platform admins grant access.** A company admin cannot give anyone access to another company. A disabled
  company or user cannot be entered.
- **Making a platform admin** (access to every company as admin) can also be done from **People**, for an active company
  admin. You cannot change your own status, and giving someone the flag replaces any per-company access they had.
  The operator command still works as the way to create the first one:

```bash
php artisan bms:platform-admin alice            # grant
php artisan bms:platform-admin alice --revoke   # remove
```

**Treat platform-admin accounts as the most sensitive in the system.** One compromised account can read and change
every company's data, and can make more platform admins. Keep the number small, use strong unique passwords, and
review `platform_access_started` and `platform_admin_granted` entries. An access grant is far narrower: one company,
one role.

## How tenant isolation works

1. The company comes from the database on every request, never from the request body or token. The only way to
   change it is the platform admin switch above, which is checked and audited.
2. Every tenant model (`BelongsToCompany`) carries a global scope that restricts it to that company and returns
   nothing if no company is set. It also refuses to save a row for a different company.
3. Controllers and report queries also filter by company explicitly.

**This is weaker than the Postgres version.** MariaDB has no row-level security, so there is no database-level
backstop: raw `DB::table(...)` queries bypass the scope. Treat any raw query on tenant tables as needing an explicit
`company_id` filter, and add a test for it. `users` and `companies` are not scoped (login has to find a user before
the company is known), and the isolation tests cover them.

The audit trail is still write-once: database triggers reject UPDATE and DELETE.

## Test engine

Runs every minute through the scheduler (`php artisan bms:engine` runs one pass by hand).

**Nothing runs unless the engine does.** Queued and scheduled tests only happen while `php artisan schedule:work` (or the
cron entry) is running. If a test sits at *pending*, that is the first thing to check.

### Test schedules
The Tests page has a **Test schedules** card where company admins set up recurring tests (managers can see them):
- **What to test:** every server, **several chosen servers** (a checklist with *Select all*), or one backup job. For each server it is the
  newest successful backup of a kind the test can use. The schedule lists the backup types it covers (Acronis, Robocopy and so on).
  File tests (restore, write and read-back, full read-back, permissions) can only read file-copy backups, so platform backups such as
  Acronis are left out of them. A Recovery point check, and any test done by a person, covers every type, each one separately.
- **How often:** every day, week or month at a time of day in a time zone you choose (it defaults to your browser's), or a
  custom cron expression. Nothing runs more often than hourly.
- **Manage:** pause and resume, edit, **Run now** (queues the tests immediately and leaves the next run alone) and delete.
  Every change is in the audit log. Tests a schedule queues are marked *Scheduled: <name>* and count as automated.
- **Missed times:** if BMS or the engine was off at the scheduled time, the schedule runs once when it is back, not once per miss.
- **No stacking:** a job that already has a test of that type pending or running is skipped.
- **Skipping a wait:** a failed test is retried after a delay (`BMS_TEST_RETRY_DELAY_MINUTES`, 60 by default, three attempts), and
  a scheduled test waits for its time. On the Tests page a waiting test shows when it runs, and **Run now** brings it forward
  to the engine's next pass. It changes when the test runs, not how it counts: a retry is still the same attempt, and the
  failure only shows under Open failures after the last attempt. It is audited, and follows the Run tests permission (admins
  and technicians on their assigned servers).
- **Alongside the weekly check:** the built-in check (each server's newest backup every `BMS_RANDOM_RESTORE_EVERY_DAYS`
  days) still runs. It skips a job that already had a test queued inside that window, so a schedule more frequent than that
  takes over, and one less frequent leaves the weekly check running between its runs.
- Five tests are built and can be scheduled. The Tests page explains each one, says where it runs, and lists the three that are
  not built (database integrity, incremental chain, Microsoft 365 mailbox), which are recorded as skipped, never as a pass.

Wherever tests are listed (history, open failures, tests to do by hand, second checks), the server's **type of backup** is shown beside
its name, because one server can have several (Acronis, Robocopy, Rsync, Windows manual copy and so on).

### The tests
| Test | What it proves | Runs |
|---|---|---|
| Random file restore | One random file reads back and matches (and matches its source, when the job says how it is laid out). | Agent, or BMS when it can read the location |
| Location write and read-back | The backup location is reachable, writable and returns data unchanged. It writes a 256 KB file in a hidden folder, reads it back and deletes it, and reports free space. It does not test your backup software. | Agent only |
| Full read-back | Every file in the backup can be read from start to end. It restores nothing and does not show a server would boot. It reads all the data, so schedule it weekly or monthly. It has its own time limit (`full_read_timeout_seconds` on the restore-test action, 4 hours by default). | Agent only |
| File permissions | Mode, owner and group (Linux) or the read-only, hidden and system flags (Windows) match on up to ten sampled files. Windows ACLs are not compared and the built-in robocopy does not copy them. Skipped, not passed, if the job has no layout or no sampled file has a source the agent may read. | Agent only |
| Recovery point | A successful backup exists and is recent enough: one and a half schedule intervals plus two hours, or `BMS_RECOVERY_POINT_MAX_AGE_HOURS` (36) when the job has no schedule. Works for every method including Acronis, each method checked on its own. It does not check the data. | BMS, no agent |

### Which tests a company uses
Not every company has every backup solution, so tests can be limited per company, by two different people:
- **The platform admin offers a subset.** On the Companies page, *Tests* lists the tests and which ones that company is offered.
  A company cannot turn on a test it was not offered.
- **The company admin turns off what it does not need.** On the Tests page, *Tests this company uses* lets a company admin turn
  off any offered test, for example Microsoft 365 for a company with no Microsoft 365 backup, and turn it on again later.
- A test is in use only when both are on. A company with no settings has every test, so nothing changes until someone changes it.
  If the platform admin offers a test again, a test the company had turned off stays off.
- **What happens when a test is off:** it cannot be queued, started by hand, or scheduled, and the forms stop offering it. Open
  tests of that kind are cancelled (by hand) or skipped (automatic) with the reason. Its schedules stop queueing and carry on to
  their next time, so turning the test back on resumes them. The weekly automatic check stops if *Random file restore* is off.
  Results already recorded, and the help text, stay visible.
- Every change is in the company's own audit trail, including one made by a platform admin, which is marked as such.

### Microsoft 365 tenants
A Microsoft 365 tenant is not a machine, so it is stored as a server of type **Microsoft 365 tenant**: no IP address, no agent, and
only the Acronis Microsoft 365 or Microsoft native 365 backup methods. Its jobs, schedules, tests and reports work like any other.
It carries a list of **mailboxes or sites to sample** (one per line). Each test by hand picks the one tested least recently.
BMS does not yet read Microsoft 365 backup status from Acronis (the sync only reads machines), so the job's status is not updated
automatically; the Recovery point check and tests by hand are what cover it today.

### Testing a Microsoft 365 mailbox by hand
The test is: restore an item chosen at random, and confirm it is complete and opens. Nobody reads a real person's mail and nobody
asks the owner for an original.
- **The item is chosen for the tester.** BMS picks the test mailbox, then a random weekday in the last 14 days
  (`BMS_MANUAL_PICK_WINDOW_DAYS`) and a position (`BMS_MANUAL_PICK_MAX_POSITION`, up to 10): "Open the 3rd email received on
  Wednesday 7 October in the Inbox, counting from the earliest that day." The tester cannot pick an easy one. If that day has
  fewer emails, they take the last one that day, or the nearest earlier weekday that has mail, and say so.
- **The tester records the item as fields**, not free text: the newest recovery point's date and time, the item's date, time, subject
  and size, which email that day it was, and whether any attachment opens. Dates and times are read in the **time zone of the
  backup console**, which is set on the tenant.
- **BMS checks the record holds together** before a pass is allowed: every field is filled in; the item is not dated after its
  recovery point; the recovery point is not in the future and is recent (the same allowance as the Recovery point test); the
  attachment opens; and the item is the one asked for, or an earlier one with the reason given. A made-up record has to be consistent
  to get through, which is harder than a made-up note.
- **Screenshots are the proof.** The steps that restore and open the item each need a screenshot (PNG or JPEG), and the test
  cannot pass without them. BMS does not read the screenshots; a person does, which is what the second check is for.
- **Reference items are optional.** A tenant that keeps known files with recorded fingerprints (`name | fingerprint | where` on
  the tenant) gets one extra step: restore that file and enter the restored copy's fingerprint, which BMS compares with the one it
  holds. A different one can never pass. It is a stronger check on top, not the main one. BMS trusts the fingerprint an admin
  recorded.

### Checking by a second person
A share of passed tests done by hand, picked at random, is checked by someone else (`BMS_MANUAL_VERIFY_PERCENT`, 20 by default).
- Nobody knows which when they do the test. The admins are notified, and the test appears under *Waiting for a second check* on the
  Tests page, where a different technician (or admin) opens it, sees the details and the screenshots, and **confirms** or
  **rejects** it. You cannot check your own test.
- A **rejection needs a reason and turns the pass into a failure** everywhere: server health, a failure record, a notification and the
  audit log, exactly as if the test had failed. The original result stays visible, marked as rejected on review.
- Until it is checked, the pass counts. An admin can also send any passed test for checking by hand.
- Failures are not sampled, because a failure already raises an alert and gets reviewed.
- Screenshots can be viewed in the page, but only real PNG or JPEG files, confirmed by their content. Everything else is a download.
- **What this cannot do:** it cannot stop two technicians who agree to cheat, or prove an item exists in a mailbox BMS cannot see.
  It makes a lazy or careless pass harder and puts a second pair of eyes on a sample.

### Tests done by hand
Any test can be done by a person, and for Microsoft 365 and platform backups it is the only way BMS has to test them.
- **Create one:** *Test by hand* on a job (Backups page), or schedule tests as **done by a person** (the schedule's *Done by*
  field). A schedule creates them at the set time, for every method, with the sample item already chosen.
- **Do it:** open it under **Tests to do by hand** on the Tests page. The tester sees the sample item (the mailbox), starts the test
  (it is then theirs; an admin can take it over), follows the checklist for that test type, and attaches evidence to each step:
  a note, the SHA-256 (or SHA-1, SHA-512, MD5) of the original and the restored copy, and screenshots or documents.
- **Evidence rules:** a fingerprint must be real hex of the right length (capitals and spaces are tidied) so a typo cannot pass
  for a match. If an original and restored fingerprint are both given and differ, the test **cannot** be passed. Files are PNG,
  JPG, PDF, TXT, CSV, JSON or LOG up to 5 MB, 20 per test, checked by content as well as name, stored privately under random names
  and only ever downloaded (never shown in the page).
- **Finishing:** *Complete as passed* needs every step ticked and the evidence the checklist marks as required. *Complete as
  failed* needs notes and records a failure, an alert and an entry in Open failures like any other. Both go through the same path
  as automated results, so server health, reports and the audit log treat them the same. A manual failure is not retried quietly,
  because a person already judged it. Everything the tester does is in the audit log.
- **Who:** admins and technicians on their assigned servers do tests. Managers can read them and download the evidence.
  The engine never picks up a manual test, and a manual test does not stop the weekly automatic one.

An agent only runs a test it says it can (agents older than 1.9 can only do a random restore), so an older agent is never
asked for a test it would quietly turn into a random restore. A server with no agent has these tests skipped with that reason.

- **Random file restore** for jobs that use a **backup location** (see below). It picks a random file in the
  job's location, copies it to a staging folder, compares SHA-256 of both, and removes the copy. Queued weekly per
  server against the latest successful job, or on demand with `POST /api/backup-jobs/:id/test` (picked up on the
  next scheduler tick, within a minute). If the server has an agent, the agent does the test on its own machine.
- **Retries:** a failed test is retried quietly after an hour, up to 3 attempts. Only the last failure creates a
  failure record and alerts admins, with a storage check added to the message.
- **Escalation:** a high or critical failure unresolved after 4 hours, then 8 hours, notifies managers as well.
- Anything the engine cannot run (other test types, platform backups, a job with no location) is recorded as **skipped** with the
  reason. It is never counted as a pass.
- A database lock stops two engine processes running at once.
- **A test is a point in time.** It says the backup was fine when it ran, and says nothing about later changes. So a
  pass only counts as current for `BMS_TEST_FRESH_DAYS` (default 14). After that the server shows **Test overdue**
  instead of Healthy until it is tested again. The DR report applies its own 30-day limit.

### Comparing a backed-up file with its source

When a job says how it is laid out, a restore test run by the agent **on the source server** also finds the file the
backup came from and compares the two. Set the job's **layout** (in the job's *Scope* dialog) to match how your tool
writes to the destination: the contents of the one source folder directly in the destination folder (typical robocopy
or rsync of one folder), one sub-folder per source folder named after it, or the full original path recreated. BMS
sends the agent the job's folders, exclusions and layout. The agent only reads source folders listed under
`source_roots` in its own config (`--source-root` at install), so BMS cannot make it read anything else.

The test samples up to 10 random backup files and uses the first whose source can be found and has **not been modified
since it was backed up**. Then it checks the restored copy against that source file:

- **Matches:** the result shows *Matches*. This is stronger evidence than copy integrity alone.
- **Differs although the source did not change:** a **critical failure**, handled like any hash mismatch.
- **Nothing could be compared** (sources edited since, deleted, outside the allowed folders, or no `source_roots`): the
  test still passes on copy integrity and says *Not compared* with the reason. A source that changed is never a failure.

A test run by BMS itself cannot read a remote source, so it does not attempt the comparison and shows nothing. The
compliance report counts matched, differing and not-compared tests.

## Backup locations

A **backup location** is a named destination for BMS-managed backups: rsync, robocopy, Windows or Linux manual
copies, and other manual copies. Add them under **Locations** (admins; everyone else can view), then pick one on each
backup job. A server with two destinations has two jobs, one per location.

- **Restore tests use the job's location.** BMS reads it itself if the location has a **path on the BMS server** (a
  local folder or a mounted share). If only the server's agent can reach it, leave the path empty: the test then runs
  on the agent, and a job with no location is skipped with that reason.
- **Platform backups use the platform's own locations.** Acronis, Veeam and the other platform methods cannot be
  given a BMS location (the API refuses it), and BMS does not restore-test them itself. Their status comes from the
  platform, and each platform's own verification covers the data.
- **Agents get a key, never a path.** Each location has a short key such as `nas-main`. BMS sends only the key, and
  the agent maps it to a folder in its own config (`"locations": {"nas-main": "/mnt/nas/backups"}`). A test for a key
  the agent has no path for is skipped, not guessed.
- A location in use cannot be deleted, and its key cannot change while jobs use it. Paths must be absolute with no
  `..`. Set `BMS_LOCATION_PATH_ROOTS` to confine BMS-server paths to certain folders.
- The old single "backup path" on a server was replaced by locations. Existing paths were converted automatically.

### What a job backs up, and where it lands

- **Scope.** Each job states what it covers: **full server**, or **specific folders** (a list of absolute paths, Unix
  or Windows style, no `..`). Leave it unstated if you do not know yet. This is what the admin says the job covers.
  BMS records it and shows it on the Backups page and in the DR report ("Backs up (as stated)"), but does not enforce
  it on the backup tool.
- **Exclusions.** A job can leave folders out, for example back up `C:\Folder` but not `C:\Folder\Sub1`. With a folder
  list, every exclusion must sit inside one of the folders being backed up, so it is refused if it would change
  nothing (outside them), cancel a folder you back up (the same folder), or swallow one (a parent of it). Windows
  paths ignore case and trailing slashes, Unix paths do not. A full-server job can exclude any folder. Editing the
  folder list re-checks the exclusions. The DR report shows each exclusion against the job it belongs to.
- **Folder inside the location.** A job can also record where inside its location its data lands, such as
  `web-01/daily`. It is always relative (no leading slash, drive letter or `..`). **Restore tests read exactly that
  folder**, so one job's test never passes on a neighbour's files. If it resolves outside the location (for example
  through a link), it is not read. Agents receive the location key plus this relative sub-path and check it again on
  their side. BMS records the folder, and does not create it on the destination.

## Remote servers and the agent

Backups on other machines are tested, and can be started, by a small **agent** that runs on each server (`agent/`,
one Python file for Linux and Windows). Under **Agents**, an admin clicks **Enroll and get install command** for a
server and runs the command it shows on that server (as root or Administrator). The command downloads the agent from
this BMS (`/agent/bms_agent.py`), checks it can reach BMS, and installs it as a service that starts at boot and is
restarted if it stops: a systemd service on Linux, a scheduled task running as SYSTEM on Windows. The **Agents** page
then lists every agent with its status, version (and *Update available*), actions and locations, and the servers that
still have none. Updating is `--upgrade` and removing is `--uninstall`. From then on:

- **Run now** on the Backups page starts a backup through the agent, and cron schedules on jobs are started
  automatically while the agent is online.
- Restore tests for that server run on the agent, so they work for backups BMS cannot read itself.
- The agent only calls BMS (outbound HTTPS). BMS sends the name of an action, never a command line. Every agent starts
  with an empty `backup` action and company admins set or change its command on the Agents page without reinstalling.
  That means control of BMS (or a company admin account) is control of what runs on those servers, as root or SYSTEM, so
  changes are audited and an agent can be installed with `--no-remote-actions` to keep its config file as the only
  authority. See `agent/README.md` for set-up and what it does and does not prove.
- Only admins can enroll agents and start backups. Technicians can still queue tests on their assigned servers.

## External backup platforms (Acronis)

Some servers are backed up by a platform that BMS does not run, such as Acronis Cyber Protect Cloud. An admin adds a
**connection** under **Integrations** (datacenter URL plus an API client ID and secret), then links each server to its
device from the server's page (**Link to platform device**, picked from the platform's own device list).

- BMS **reads** each linked device's backup status every 15 minutes (and on **Sync now**). It never changes anything
  on the platform. Create the API client in the Acronis console with a read-only role.
- The latest run is turned into the server's backup status: **success**, **failed** (the latest run did not
  succeed), or **warning** (no success within the connection's limit, 36 hours by default). A device with no backup
  data is **unknown** and changes nothing. Only policies whose type mentions "backup" count, so antimalware or patch
  runs are never mistaken for backups.
- Each newly seen run is recorded as a backup (method `acronis`, "Reported by ...") so the dashboard and reports
  count it. A sync sees the latest run only, so several runs between two syncs appear as one.
- Admins are alerted once when a server turns failed or overdue, and once per outage if the platform cannot be read.
- Secrets are encrypted with `APP_KEY` and never returned by the API. **Changing `APP_KEY` makes stored connections
  unreadable**, and they have to be added again. BMS only calls `https://` hosts under `acronis.com`
  (`BMS_INTEGRATION_HOST_SUFFIXES`), so it cannot be pointed at internal addresses.
- This is the platform's word, not BMS's proof. Reports say so. For BMS-verified restore tests on such a server, add an
  agent as well.
- Written from Acronis's published API documentation. Check it against your own console with
  `php artisan bms:acronis-probe <connection id>`, which prints the response structure (field names and types, never
  device names or secrets).

### Bringing the devices into BMS

- **Import devices** (per connection) lists the devices not yet linked, marks which have a protection plan and which
  would match an existing server by name, and creates servers for the ones you tick. The server gets the platform as
  its backup method and the operating system you choose for the batch (the platform data does not say). A device
  whose name matches an existing server is linked to it instead of duplicated, unless you switch that off. The
  company's server limit is respected, and a device is never imported twice. It uses the connection's saved API
  client, so there is no separate login. The same call is available as `POST /api/integrations/{id}/import`.
- **Auto-import** (per connection, off by default) does this hands-off: each sync adds devices that have a
  protection plan and are not in BMS yet. At most 25 per sync (`BMS_AUTO_IMPORT_MAX_PER_SYNC`), within the server
  limit. It never touches an existing server, even one with the same name. Admins are notified of what was added,
  and it is audited as done by the system. Turning it on is an audited admin action.
- "Protected" means a plan is applied. The Acronis data BMS reads shows plans and not licences, so check what is
  imported against your licensing. Run `php artisan bms:acronis-probe` to see which fields your console returns, and
  the filter can be tightened to match.

Other platforms (Veeam, Commvault, Microsoft 365, AWS, Azure) are not built. Each would be another adapter behind the
same connection and link model.

## Two-step sign-in (MFA)

Everyone must sign in with a password and a 6-digit code from an authenticator app (Microsoft Authenticator, Google Authenticator, Authy and similar). It is on by default. Set `BMS_MFA_REQUIRED=false` in `.env` to make it optional, in which case people can still turn it on for themselves from their profile.

- **First sign-in.** Someone who has not set it up yet can sign in, but the app and the API stay locked until they scan the QR code, enter a code to prove it works, and save their ten recovery codes. This applies to sessions that were already open when you switched it on.
- **Lost phone.** Each recovery code works once. If those are gone too, a company admin can reset the person's two-step sign-in from the Users page (a platform admin does the same for platform admins and from the People page). The reset signs them out everywhere and they set it up again at their next sign-in. From the server: `php artisan bms:mfa-reset <username>`.
- **How it is protected.** Secrets are encrypted with `APP_KEY`. Recovery codes are stored as keyed hashes, so nobody can read them back. A code can only be used once, a wrong guess is limited per sign-in attempt, per person and per address, and the pending sign-in expires after five minutes.
- **Scripts.** A login call now returns `mfa_required` and an `mfa_token`, and no session. Finish with `POST /api/auth/mfa/verify` and either `code` or `recovery_code`. Anything that logs in with a bare username and password will stop working, so give automation its own plan before you switch this on.
- **Limit.** This is a code that can be phished like any other; it stops password reuse and guessing, not a convincing fake login page.

## Reports

Four reports under **Reports** (admins and managers): compliance, SLA performance, security and access, and DR
readiness. Each is saved as a frozen snapshot. `GET /api/reports/:id/view` is a print-ready page; use the browser's
Print, then Save as PDF. Last month's compliance report and last quarter's SLA report are archived automatically.

- **Only recorded data.** Anything BMS cannot measure (uptime, RTO and RPO actuals, encryption, storage use, service
  credits, framework attestations, DR documentation) is not filled in. Each report ends with a "Not measured by BMS"
  list.
- **Compliance level** is rule-based: non-compliant if backup success, test coverage or test pass rate is under
  target (99%, 95%, 95% by default, overridable per request) or a critical or high failure is unresolved; compliant
  with notes if only lower-severity failures are open; insufficient data if no backups or no tests finished.
- **DR readiness** per server: latest backup succeeded within 24 hours, latest test passed within 30 days, and no
  unresolved critical or high failures.

## Decisions where the blueprint conflicted or was silent

- **User model:** v3.0 (single company per user) is followed. The `MULTI_COMPANY_*` documents are treated as
  superseded. The platform-admin flag above is the one cross-company capability, added at the owner's request.
- **API paths:** `/api/servers`, `/api/backup-jobs`, etc., scoped by session, per v3.0.
- **Health score:** not defined in the blueprint. Half backup success rate, half test pass rate over 30 days. With no
  data it is empty, not 100.
- **Test results:** there is intentionally no API to post a test result. Results are written only by
  `TestResults::record()`, which the engine calls, so a result cannot be faked through the API.
- **Passwords:** minimum 12 characters.

## Not built yet

- LDAP login (users can be created as `ldap`, but logging in returns a clear "not configured" error)
- Vendor-managed backups (Veeam, Commvault, Acronis, M365, AWS, Azure) and the other seven test types
- Email, SMS and Slack delivery (including emailing reports), server-side PDF files, the tender response pack,
  digital signatures, document upload for failures
- Disabling a company from the UI, Docker packaging
- Mobile app
