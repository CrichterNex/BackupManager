# Backup Management System (BMS)

Multi-tenant platform for tracking backups across companies and proving they can be restored.
One Node/TypeScript app serves both the JSON API (`/api`) and the web UI (`/`). There is no separate
frontend project or deployment.

Built from the blueprint package (v3.0, single company per user).

## Run it

```bash
npm install
cp .env.example .env            # set JWT_SECRET (openssl rand -hex 32) and DATABASE_URL
npm run migrate                 # also runs automatically on server start
npm run company:create -- --name "TechCorp Inc" --slug techcorp-inc --admin alice --email alice@techcorp.example
npm run dev                     # http://localhost:3000
```

Or `docker compose up --build` (needs `JWT_SECRET` in the environment).

`npm test` runs the integration suite against a real Postgres (`bms_test` by default, override with
`TEST_DATABASE_URL`). `npm run typecheck` and `npm run build` check types and compile to `dist/`.

## What exists (blueprint Phase 1)

- Companies, users, servers, backup jobs, test executions, test failures, notifications, audit trail
- Login (bcrypt, 8-hour HttpOnly cookie, login rate limit, 1000 requests/hour per user)
- Roles: company admin, manager (read-only), technician (assigned servers only)
- Web UI: dashboard, servers, backups, tests and failure resolution, users, audit log, notifications

## How tenant isolation works

Three layers, as the spec asks:

1. The session's company comes from the database on every request, never from the request body or token.
2. Every query filters by that company.
3. Postgres row-level security (`FORCE`d) on every tenant table. It only lets a transaction see rows for the
   company set by `withCompany()` in `src/db.ts`, and returns nothing if no company is set.

`users` and `companies` are not under RLS because login has to look a user up before the company is known;
those queries are filtered in code and covered by tests.

The audit trail is write-once: a database trigger rejects UPDATE and DELETE.

## Decisions where the blueprint conflicted or was silent

- **User model:** v3.0 (single company per user) is followed. The `MULTI_COMPANY_*` documents are treated as
  superseded. The master blueprint's super-admin and `/admin/*` routes are not built, because v3.0 forbids
  cross-company features. Companies are created by an operator with `npm run company:create`.
- **API paths:** `/api/servers`, `/api/backup-jobs`, etc., scoped by session, per v3.0. `/api/companies/:id/dashboard`
  exists and returns 403 for any company but your own.
- **Schema:** `database_schema.md` is the old single-tenant schema (UUIDs, no company). Integer keys and
  `company_id` follow the multi-tenant documents.
- **Health score:** not defined in the blueprint. Implemented as half backup success rate, half test pass rate
  over 30 days. With no data it is empty, not 100.
- **Test results:** there is intentionally no API to post a test result. Results are written only by
  `recordTestResult()` in `src/services.ts`, which the Phase 3 engine will call, so a result cannot be faked
  through the API.
- **Passwords:** minimum 12 characters.

## Not built yet

- LDAP login (users can be created as `ldap`, but logging in returns a clear "not configured" error)
- Backup method integrations, the automated test engine and scheduler, email/SMS/Slack delivery, reports and
  PDF export, document upload for failures (Phases 2 to 4)
- Mobile app
