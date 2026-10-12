-- BMS Phase 1 schema.
-- Model: v3.0 single company per user. Every tenant table carries company_id and is
-- protected by row-level security keyed on the app.company_id session setting.

CREATE TABLE companies (
  id                 SERIAL PRIMARY KEY,
  name               VARCHAR(255) NOT NULL,
  slug               VARCHAR(100) NOT NULL UNIQUE,
  contact_name       VARCHAR(255),
  contact_email      VARCHAR(255),
  contact_phone      VARCHAR(30),
  subscription_tier  VARCHAR(50) NOT NULL DEFAULT 'basic',
  max_servers        INTEGER,
  is_active          BOOLEAN NOT NULL DEFAULT true,
  created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT valid_tier CHECK (subscription_tier IN ('basic', 'pro', 'enterprise')),
  CONSTRAINT valid_slug CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')
);

CREATE TABLE users (
  id             SERIAL PRIMARY KEY,
  company_id     INTEGER NOT NULL REFERENCES companies(id),
  username       VARCHAR(100) NOT NULL,
  email          VARCHAR(255) NOT NULL,
  first_name     VARCHAR(100),
  last_name      VARCHAR(100),
  phone          VARCHAR(30),
  auth_method    VARCHAR(10) NOT NULL DEFAULT 'local',
  password_hash  VARCHAR(255),
  ldap_user      VARCHAR(100),
  role           VARCHAR(30) NOT NULL,
  active         BOOLEAN NOT NULL DEFAULT true,
  last_login     TIMESTAMPTZ,
  created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT valid_role CHECK (role IN ('company_admin', 'company_manager', 'company_technician')),
  CONSTRAINT valid_auth_method CHECK (auth_method IN ('local', 'ldap')),
  CONSTRAINT local_needs_password CHECK (auth_method <> 'local' OR password_hash IS NOT NULL)
);
-- Usernames are global because login happens before the company is known.
CREATE UNIQUE INDEX idx_users_username ON users (LOWER(username));
CREATE INDEX idx_users_company ON users (company_id);

CREATE TABLE servers (
  id                    SERIAL PRIMARY KEY,
  company_id            INTEGER NOT NULL REFERENCES companies(id),
  hostname              VARCHAR(255) NOT NULL,
  ip_address            INET,
  os_type               VARCHAR(20) NOT NULL,
  os_name               VARCHAR(100),
  criticality           VARCHAR(20) NOT NULL DEFAULT 'standard',
  backup_methods        TEXT[] NOT NULL DEFAULT '{}',
  backup_location_path  VARCHAR(512),
  retention_policy_days INTEGER,
  active                BOOLEAN NOT NULL DEFAULT true,
  last_backup_time      TIMESTAMPTZ,
  last_backup_status    VARCHAR(20),
  last_test_time        TIMESTAMPTZ,
  last_test_status      VARCHAR(20),
  notes                 TEXT,
  created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT valid_os_type CHECK (os_type IN ('linux', 'windows')),
  CONSTRAINT valid_criticality CHECK (criticality IN ('critical', 'standard', 'non-critical')),
  CONSTRAINT valid_retention CHECK (retention_policy_days IS NULL OR retention_policy_days > 0),
  CONSTRAINT valid_methods CHECK (backup_methods <@ ARRAY[
    'rsync', 'acronis', 'veeam', 'commvault', 'windows_manual_copy', 'linux_manual_copy',
    'acronis_m365', 'microsoft_native_365', 'aws_backup', 'azure_backup']::TEXT[])
);
-- Hostnames only need to be unique within a company.
CREATE UNIQUE INDEX idx_servers_company_hostname ON servers (company_id, LOWER(hostname)) WHERE active;
CREATE INDEX idx_servers_company ON servers (company_id);

-- Technicians only see servers they are assigned to.
CREATE TABLE server_assignments (
  server_id   INTEGER NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
  user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  company_id  INTEGER NOT NULL REFERENCES companies(id),
  PRIMARY KEY (server_id, user_id)
);
CREATE INDEX idx_server_assignments_user ON server_assignments (user_id);

CREATE TABLE backup_jobs (
  id                   SERIAL PRIMARY KEY,
  company_id           INTEGER NOT NULL REFERENCES companies(id),
  server_id            INTEGER NOT NULL REFERENCES servers(id),
  backup_method        VARCHAR(50) NOT NULL,
  schedule             VARCHAR(100),                 -- cron expression
  retention_days       INTEGER,
  incremental          BOOLEAN NOT NULL DEFAULT false,
  status               VARCHAR(20) NOT NULL DEFAULT 'scheduled',
  status_message       TEXT,
  size_gb              NUMERIC(12, 2),
  files_count          INTEGER,
  started_time         TIMESTAMPTZ,
  completed_time       TIMESTAMPTZ,
  recovery_point_date  TIMESTAMPTZ,
  test_status          VARCHAR(20),                  -- latest test outcome: passed / failed
  created_by           INTEGER REFERENCES users(id),
  created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  deleted_at           TIMESTAMPTZ,
  CONSTRAINT valid_job_method CHECK (backup_method IN (
    'rsync', 'acronis', 'veeam', 'commvault', 'windows_manual_copy', 'linux_manual_copy',
    'acronis_m365', 'microsoft_native_365', 'aws_backup', 'azure_backup')),
  CONSTRAINT valid_job_status CHECK (status IN ('scheduled', 'running', 'success', 'warning', 'failed')),
  CONSTRAINT valid_job_retention CHECK (retention_days IS NULL OR retention_days > 0)
);
CREATE INDEX idx_backup_jobs_company ON backup_jobs (company_id, created_at DESC);
CREATE INDEX idx_backup_jobs_server ON backup_jobs (server_id, created_at DESC);
CREATE INDEX idx_backup_jobs_status ON backup_jobs (company_id, status);

CREATE TABLE backup_test_executions (
  id                SERIAL PRIMARY KEY,
  company_id        INTEGER NOT NULL REFERENCES companies(id),
  backup_job_id     INTEGER NOT NULL REFERENCES backup_jobs(id),
  test_type         VARCHAR(50) NOT NULL,
  test_status       VARCHAR(20) NOT NULL DEFAULT 'pending',
  hash_algorithm    VARCHAR(20),
  hash_original     VARCHAR(128),
  hash_restored     VARCHAR(128),
  hash_match        BOOLEAN,
  scheduled_time    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  started_time      TIMESTAMPTZ,
  completed_time    TIMESTAMPTZ,
  duration_seconds  INTEGER,
  error_message     TEXT,
  executed_by       INTEGER REFERENCES users(id),    -- NULL = automated
  notes             TEXT,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT valid_test_type CHECK (test_type IN (
    'synthetic_file', 'random_restore', 'full_recovery', 'database_integrity',
    'incremental_chain', 'm365_mailbox', 'recovery_point', 'file_permissions')),
  CONSTRAINT valid_test_status CHECK (test_status IN (
    'pending', 'running', 'success', 'failed', 'skipped', 'cancelled'))
);
CREATE INDEX idx_test_exec_company ON backup_test_executions (company_id, created_at DESC);
CREATE INDEX idx_test_exec_job ON backup_test_executions (backup_job_id);
CREATE INDEX idx_test_exec_status ON backup_test_executions (company_id, test_status);

CREATE TABLE backup_test_failures (
  id                 SERIAL PRIMARY KEY,
  company_id         INTEGER NOT NULL REFERENCES companies(id),
  test_execution_id  INTEGER NOT NULL REFERENCES backup_test_executions(id),
  failure_type       VARCHAR(50) NOT NULL,
  severity           VARCHAR(20) NOT NULL,
  failed_item        VARCHAR(500),
  expected_value     TEXT,
  actual_value       TEXT,
  error_message      TEXT,
  resolved           BOOLEAN NOT NULL DEFAULT false,
  resolution_type    VARCHAR(50),
  resolution_notes   TEXT,
  resolved_by        INTEGER REFERENCES users(id),
  resolved_at        TIMESTAMPTZ,
  backup_usable      BOOLEAN,
  created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT valid_failure_type CHECK (failure_type IN (
    'file_missing', 'hash_mismatch', 'corruption', 'timeout', 'access_denied', 'restore_failed')),
  CONSTRAINT valid_severity CHECK (severity IN ('critical', 'high', 'medium', 'low')),
  CONSTRAINT valid_resolution CHECK (resolution_type IS NULL OR resolution_type IN (
    'retest_passed', 'backup_rerun', 'backup_fixed', 'false_positive', 'known_issue')),
  CONSTRAINT resolved_has_detail CHECK (
    NOT resolved OR (resolution_type IS NOT NULL AND resolved_by IS NOT NULL AND resolved_at IS NOT NULL))
);
CREATE INDEX idx_test_failures_company ON backup_test_failures (company_id, resolved);

CREATE TABLE notifications (
  id           SERIAL PRIMARY KEY,
  company_id   INTEGER NOT NULL REFERENCES companies(id),
  user_id      INTEGER NOT NULL REFERENCES users(id),
  event_type   VARCHAR(50) NOT NULL,
  resource_type VARCHAR(50),
  resource_id  INTEGER,
  subject      VARCHAR(255),
  message      TEXT NOT NULL,
  is_read      BOOLEAN NOT NULL DEFAULT false,
  read_at      TIMESTAMPTZ,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_notifications_user ON notifications (user_id, is_read, created_at DESC);

CREATE TABLE audit_trail (
  id             BIGSERIAL PRIMARY KEY,
  company_id     INTEGER REFERENCES companies(id),   -- NULL for events with no tenant (failed logins)
  user_id        INTEGER REFERENCES users(id),
  action         VARCHAR(100) NOT NULL,
  resource_type  VARCHAR(50),
  resource_id    INTEGER,
  details        JSONB,
  ip_address     INET,
  success        BOOLEAN NOT NULL DEFAULT true,
  created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_audit_company_created ON audit_trail (company_id, created_at DESC);
CREATE INDEX idx_audit_action ON audit_trail (action);
CREATE INDEX idx_audit_resource ON audit_trail (resource_type, resource_id);

-- The audit trail is write-once: block UPDATE and DELETE at the database, not just in the app.
CREATE FUNCTION audit_trail_immutable() RETURNS trigger AS $$
BEGIN
  RAISE EXCEPTION 'audit_trail is immutable';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER audit_trail_no_change BEFORE UPDATE OR DELETE ON audit_trail
  FOR EACH ROW EXECUTE FUNCTION audit_trail_immutable();

-- Row-level security -------------------------------------------------------
-- app.company_id is set per transaction by the API (see src/db.ts). When it is unset,
-- current_setting(..., true) returns NULL and no rows match, so the default is deny.
CREATE FUNCTION app_company_id() RETURNS integer AS $$
  SELECT NULLIF(current_setting('app.company_id', true), '')::integer
$$ LANGUAGE sql STABLE;

DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['servers', 'server_assignments', 'backup_jobs', 'backup_test_executions',
                           'backup_test_failures', 'notifications', 'audit_trail']
  LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (company_id = app_company_id()) WITH CHECK (company_id = app_company_id())', t);
  END LOOP;
END $$;

-- Failed logins have no tenant, so audit_trail also accepts company-less inserts.
CREATE POLICY system_events_insert ON audit_trail FOR INSERT WITH CHECK (company_id IS NULL);
