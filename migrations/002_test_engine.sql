-- Phase 3: test engine support (retries and failure escalation).

ALTER TABLE backup_test_executions
  ADD COLUMN retry_count INTEGER NOT NULL DEFAULT 0,
  ADD COLUMN retry_of    INTEGER REFERENCES backup_test_executions(id),
  ADD COLUMN details     JSONB;

-- 0 = not escalated, 1 = escalated after 4 hours unresolved, 2 = after 8 hours.
ALTER TABLE backup_test_failures
  ADD COLUMN escalation_level INTEGER NOT NULL DEFAULT 0,
  ADD CONSTRAINT valid_escalation_level CHECK (escalation_level BETWEEN 0 AND 2);

CREATE INDEX idx_test_exec_due ON backup_test_executions (scheduled_time) WHERE test_status = 'pending';
