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

The flag is set by an operator, never through the API or UI:

```bash
php artisan bms:platform-admin alice            # grant
php artisan bms:platform-admin alice --revoke   # remove
```

**Treat platform-admin accounts as the most sensitive in the system.** One compromised account can read and change
every company's data. Keep the number small, use strong unique passwords, and review `platform_access_started`
entries.

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
- The agent only calls BMS (outbound HTTPS) and only runs actions named in its own local config. BMS sends a name,
  never a command line. See `agent/README.md` for set-up and what it does and does not prove.
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
