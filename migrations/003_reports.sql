-- Phase 4: archived reports. Each row is a frozen snapshot of the figures at generation time, so a report
-- handed to a client or auditor can be reproduced exactly later.

CREATE TABLE reports (
  id            SERIAL PRIMARY KEY,
  company_id    INTEGER NOT NULL REFERENCES companies(id),
  report_type   VARCHAR(30) NOT NULL,
  period_start  TIMESTAMPTZ NOT NULL,
  period_end    TIMESTAMPTZ NOT NULL,
  data          JSONB NOT NULL,
  generated_by  INTEGER REFERENCES users(id),   -- NULL = generated automatically
  created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT valid_report_type CHECK (report_type IN ('compliance', 'sla', 'security', 'dr_readiness')),
  CONSTRAINT valid_report_period CHECK (period_end >= period_start)
);
CREATE INDEX idx_reports_company ON reports (company_id, created_at DESC);
CREATE INDEX idx_reports_type_period ON reports (company_id, report_type, period_start);

ALTER TABLE reports ENABLE ROW LEVEL SECURITY;
ALTER TABLE reports FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON reports USING (company_id = app_company_id()) WITH CHECK (company_id = app_company_id());
