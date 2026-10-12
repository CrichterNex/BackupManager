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

- **Random file restore** for `rsync`, `windows_manual_copy` and `linux_manual_copy` servers that have a
  `backup_location_path` the BMS host can read. It picks a random file, copies it to a staging folder, compares
  SHA-256 of both, and removes the copy. Queued weekly per server against the latest successful job, or on demand
  with `POST /api/backup-jobs/:id/test` (picked up on the next scheduler tick, within a minute).
- **Retries:** a failed test is retried quietly after an hour, up to 3 attempts. Only the last failure creates a
  failure record and alerts admins, with a storage check added to the message.
- **Escalation:** a high or critical failure unresolved after 4 hours, then 8 hours, notifies managers as well.
- Anything the engine cannot run (other test types, vendor methods, no path) is recorded as **skipped** with the
  reason. It is never counted as a pass.
- A database lock stops two engine processes running at once.

## Remote servers and the agent

Backups on other machines are tested, and can be started, by a small **agent** that runs on each server (`agent/`,
one Python file for Linux and Windows). In **Servers**, an admin clicks **Enroll agent** and copies the config shown
once. From then on:

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
