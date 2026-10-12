import type { Db } from './db.js';

/** Notify every active admin in the company. Runs inside the caller's company transaction. */
export async function notifyAdmins(
  db: Db,
  companyId: number,
  n: { event_type: string; subject: string; message: string; resource_type?: string; resource_id?: number },
): Promise<void> {
  await db.query(
    `INSERT INTO notifications (company_id, user_id, event_type, resource_type, resource_id, subject, message)
     SELECT $1, id, $2, $3, $4, $5, $6 FROM users WHERE company_id = $1 AND role = 'company_admin' AND active`,
    [companyId, n.event_type, n.resource_type ?? null, n.resource_id ?? null, n.subject, n.message],
  );
}

/** Notify every active user with one of the given roles. Runs inside the caller's company transaction. */
export async function notifyRoles(
  db: Db,
  companyId: number,
  roles: string[],
  n: { event_type: string; subject: string; message: string; resource_type?: string; resource_id?: number },
): Promise<void> {
  await db.query(
    `INSERT INTO notifications (company_id, user_id, event_type, resource_type, resource_id, subject, message)
     SELECT $1, id, $2, $3, $4, $5, $6 FROM users WHERE company_id = $1 AND role = ANY($7::text[]) AND active`,
    [companyId, n.event_type, n.resource_type ?? null, n.resource_id ?? null, n.subject, n.message, roles],
  );
}

/** Mark a test as not run, with the reason. A skipped test is never counted as a pass. */
export async function skipTest(db: Db, companyId: number, testId: number, reason: string): Promise<void> {
  await db.query(
    `UPDATE backup_test_executions SET test_status = 'skipped', completed_time = NOW(), started_time = COALESCE(started_time, NOW()),
            error_message = $3, updated_at = NOW()
      WHERE id = $1 AND company_id = $2 AND test_status IN ('pending', 'running')`,
    [testId, companyId, reason],
  );
}

export interface TestResult {
  success: boolean;
  hash_algorithm?: string;
  hash_original?: string;
  hash_restored?: string;
  failure_type?: 'file_missing' | 'hash_mismatch' | 'corruption' | 'timeout' | 'access_denied' | 'restore_failed';
  severity?: 'critical' | 'high' | 'medium' | 'low';
  failed_item?: string;
  error_message?: string;
}

/**
 * Record the outcome of a test execution. This is the single write path for results: the Phase 3
 * test engine calls it, and there is deliberately no public API to post a result, because a
 * hand-submitted "pass" would undermine the compliance evidence the product exists to produce.
 */
export interface RetryPolicy { delayMinutes: number; maxAttempts: number }

export async function recordTestResult(
  db: Db, companyId: number, testId: number, r: TestResult, opts: { retry?: RetryPolicy } = {},
): Promise<{ retryScheduled: boolean }> {
  const { rows } = await db.query(
    `UPDATE backup_test_executions
        SET test_status = $3, completed_time = NOW(),
            started_time = COALESCE(started_time, NOW()),
            duration_seconds = GREATEST(0, EXTRACT(EPOCH FROM (NOW() - COALESCE(started_time, scheduled_time)))::int),
            hash_algorithm = $4::varchar, hash_original = $5::varchar, hash_restored = $6::varchar,
            hash_match = CASE WHEN $5::varchar IS NULL OR $6::varchar IS NULL THEN NULL ELSE $5::varchar = $6::varchar END,
            error_message = $7::text, updated_at = NOW()
      WHERE id = $1 AND company_id = $2 AND test_status IN ('pending', 'running')
      RETURNING backup_job_id, test_type, retry_count, executed_by`,
    [testId, companyId, r.success ? 'success' : 'failed', r.hash_algorithm ?? null, r.hash_original ?? null, r.hash_restored ?? null, r.error_message ?? null],
  );
  const exec = rows[0];
  if (!exec) throw new Error(`Test ${testId} is not pending or running`);

  // Level 1 of the blueprint's failure handling: a first failure is retried quietly, with no failure record or alert.
  if (!r.success && opts.retry && exec.retry_count + 1 < opts.retry.maxAttempts) {
    await db.query(
      `INSERT INTO backup_test_executions (company_id, backup_job_id, test_type, executed_by, retry_of, retry_count, scheduled_time)
       VALUES ($1,$2,$3,$4,$5,$6, NOW() + ($7 || ' minutes')::interval)`,
      [companyId, exec.backup_job_id, exec.test_type, exec.executed_by, testId, exec.retry_count + 1, String(opts.retry.delayMinutes)],
    );
    return { retryScheduled: true };
  }

  const job = (await db.query('SELECT server_id FROM backup_jobs WHERE id = $1', [exec.backup_job_id])).rows[0];
  const outcome = r.success ? 'passed' : 'failed';
  await db.query('UPDATE backup_jobs SET test_status = $2, updated_at = NOW() WHERE id = $1', [exec.backup_job_id, outcome]);
  if (job) await db.query('UPDATE servers SET last_test_time = NOW(), last_test_status = $2, updated_at = NOW() WHERE id = $1', [job.server_id, outcome]);

  if (!r.success) {
    const attempts = exec.retry_count + 1;
    const f = await db.query(
      `INSERT INTO backup_test_failures
         (company_id, test_execution_id, failure_type, severity, failed_item, expected_value, actual_value, error_message)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8) RETURNING id`,
      [companyId, testId, r.failure_type ?? 'restore_failed', r.severity ?? 'high', r.failed_item ?? null, r.hash_original ?? null, r.hash_restored ?? null, attempts > 1 ? `${r.error_message ?? 'Test failed'} (failed ${attempts} times)` : r.error_message ?? null],
    );
    await notifyAdmins(db, companyId, {
      event_type: 'test_failed',
      subject: `Backup test failed (${exec.test_type})`,
      message: (r.error_message ?? `A ${exec.test_type} test failed and needs review.`) + (attempts > 1 ? ` It failed ${attempts} times.` : ''),
      resource_type: 'test_failure',
      resource_id: f.rows[0].id,
    });
  }
  return { retryScheduled: false };
}
