import { statfs } from 'node:fs/promises';
import { config } from '../config.js';
import { ENGINE_TEST_TYPES, FILESYSTEM_METHODS } from '../constants.js';
import { pool, withCompany, withTransaction } from '../db.js';
import { notifyRoles, recordTestResult, skipTest, type TestResult } from '../services.js';
import { runRandomRestore } from './runners.js';

// Only one engine instance acts at a time, even if several app processes are running.
const ENGINE_LOCK = 727275;

interface Claimed {
  id: number;
  backup_job_id: number;
  test_type: string;
  hostname: string;
  backup_method: string;
  backup_location_path: string | null;
}

const activeCompanyIds = () =>
  withTransaction(async (db) => (await db.query('SELECT id FROM companies WHERE is_active')).rows.map((r) => r.id as number));

/** Queue a random-restore test for every server whose latest successful backup has not been tested recently. */
export async function scheduleDueTests(): Promise<number> {
  let queued = 0;
  for (const companyId of await activeCompanyIds()) {
    queued += await withCompany(companyId, async (db) => {
      const r = await db.query(
        `INSERT INTO backup_test_executions (company_id, backup_job_id, test_type)
         SELECT $1, j.id, 'random_restore'
           FROM backup_jobs j JOIN servers s ON s.id = j.server_id
          WHERE j.company_id = $1 AND j.deleted_at IS NULL AND s.active AND j.status = 'success'
            AND j.backup_method = ANY($2::text[])
            AND j.id = (SELECT MAX(id) FROM backup_jobs WHERE server_id = j.server_id AND deleted_at IS NULL AND status = 'success')
            AND NOT EXISTS (
              SELECT 1 FROM backup_test_executions t
               WHERE t.backup_job_id = j.id AND t.test_type = 'random_restore'
                 AND (t.test_status IN ('pending', 'running') OR t.created_at > NOW() - ($3 || ' days')::interval))
         RETURNING id`,
        [companyId, [...FILESYSTEM_METHODS], String(config.randomRestoreEveryDays)],
      );
      return r.rowCount ?? 0;
    });
  }
  return queued;
}

/** Take the next due test, marking it running in the same transaction so no other worker can claim it. */
async function claimNext(companyId: number): Promise<Claimed | undefined> {
  return withCompany(companyId, async (db) => {
    const { rows } = await db.query(
      `UPDATE backup_test_executions t SET test_status = 'running', started_time = NOW(), updated_at = NOW()
        WHERE t.id = (SELECT id FROM backup_test_executions WHERE company_id = $1 AND test_status = 'pending' AND scheduled_time <= NOW()
                       ORDER BY scheduled_time, id LIMIT 1 FOR UPDATE SKIP LOCKED)
        RETURNING t.id, t.backup_job_id, t.test_type`,
      [companyId],
    );
    if (!rows[0]) return undefined;
    const j = (await db.query(
      `SELECT j.backup_method, s.hostname, s.backup_location_path FROM backup_jobs j JOIN servers s ON s.id = j.server_id WHERE j.id = $1`,
      [rows[0].backup_job_id],
    )).rows[0];
    return { ...rows[0], ...j } as Claimed;
  });
}

/** Level 2 diagnostics, run before the final failure is recorded, so the failure says why and not just "failed". */
async function diagnose(path: string | null): Promise<string | undefined> {
  if (!path) return undefined;
  try {
    const s = await statfs(path);
    const free = s.bavail / Math.max(1, s.blocks);
    if (free < 0.05) return `Backup storage is nearly full (${(free * 100).toFixed(1)}% free).`;
  } catch {
    return 'The backup location could not be reached for a storage check.';
  }
  return undefined;
}

async function execute(t: Claimed): Promise<TestResult | { skip: string }> {
  if (!(ENGINE_TEST_TYPES as readonly string[]).includes(t.test_type)) {
    return { skip: `The ${t.test_type} test is not automated yet.` };
  }
  if (!(FILESYSTEM_METHODS as readonly string[]).includes(t.backup_method)) {
    return { skip: `Automated restore tests are not available for ${t.backup_method} backups yet.` };
  }
  if (!t.backup_location_path) {
    return { skip: 'The server has no backup location path, so there is nothing to restore from.' };
  }
  return runRandomRestore(t.backup_location_path, { stagingDir: config.restoreStagingDir });
}

/** Run every due test for one company. Returns how many were processed. */
export async function runDueTests(companyId: number): Promise<number> {
  let n = 0;
  for (let t = await claimNext(companyId); t; t = await claimNext(companyId)) {
    n++;
    let outcome: TestResult | { skip: string };
    try {
      outcome = await execute(t);
    } catch (err) {
      outcome = { success: false, failure_type: 'restore_failed', severity: 'high', error_message: `Test engine error: ${(err as Error).message}` };
    }
    const retry = { delayMinutes: config.testRetryDelayMinutes, maxAttempts: config.testMaxAttempts };
    const hint = !('skip' in outcome) && !outcome.success ? await diagnose(t.backup_location_path) : undefined;
    await withCompany(companyId, async (db) => {
      if ('skip' in outcome) return skipTest(db, companyId, t.id, outcome.skip);
      if (hint) outcome.error_message = `${outcome.error_message ?? 'Test failed'} ${hint}`;
      await recordTestResult(db, companyId, t.id, outcome, { retry });
    });
  }
  return n;
}

/**
 * Level 3 escalation: a high or critical failure still unresolved after 4 hours is raised to managers as well
 * as admins, and again after 8 hours. Each level is sent once.
 */
export async function escalateFailures(companyId: number): Promise<number> {
  return withCompany(companyId, async (db) => {
    const { rows } = await db.query(
      `UPDATE backup_test_failures f
          SET escalation_level = CASE WHEN f.created_at <= NOW() - INTERVAL '8 hours' THEN 2 ELSE 1 END, updated_at = NOW()
        WHERE f.company_id = $1 AND NOT f.resolved AND f.severity IN ('critical', 'high')
          AND ((f.escalation_level < 1 AND f.created_at <= NOW() - INTERVAL '4 hours')
            OR (f.escalation_level < 2 AND f.created_at <= NOW() - INTERVAL '8 hours'))
        RETURNING f.id, f.escalation_level, f.severity, f.failed_item`,
      [companyId],
    );
    for (const f of rows) {
      await notifyRoles(db, companyId, ['company_admin', 'company_manager'], {
        event_type: 'failure_escalated',
        subject: `Escalation level ${f.escalation_level}: ${f.severity} backup test failure unresolved`,
        message: `A ${f.severity} test failure${f.failed_item ? ` on ${f.failed_item}` : ''} has been unresolved for ${f.escalation_level === 2 ? '8' : '4'} hours.`,
        resource_type: 'test_failure',
        resource_id: f.id,
      });
    }
    return rows.length;
  });
}

/** One full pass: queue due tests, run what is due, escalate stale failures. Safe to call from several processes. */
export async function engineTick(): Promise<{ queued: number; ran: number; escalated: number } | undefined> {
  const lock = await pool.connect();
  try {
    const got = (await lock.query('SELECT pg_try_advisory_lock($1) AS ok', [ENGINE_LOCK])).rows[0].ok;
    if (!got) return undefined;
    try {
      const queued = await scheduleDueTests();
      let ran = 0, escalated = 0;
      for (const id of await activeCompanyIds()) {
        ran += await runDueTests(id);
        escalated += await escalateFailures(id);
      }
      return { queued, ran, escalated };
    } finally {
      await lock.query('SELECT pg_advisory_unlock($1)', [ENGINE_LOCK]);
    }
  } finally {
    lock.release();
  }
}

let timer: NodeJS.Timeout | undefined;
let running = false;

async function safeTick() {
  if (running) return;
  running = true;
  try {
    const r = await engineTick();
    if (r && (r.queued || r.ran || r.escalated)) console.log(`Test engine: queued ${r.queued}, ran ${r.ran}, escalated ${r.escalated}`);
  } catch (err) {
    console.error('Test engine error:', (err as Error).message);
  } finally {
    running = false;
  }
}

export function startEngine(): void {
  if (!config.engineEnabled || timer) return;
  timer = setInterval(safeTick, config.engineIntervalSeconds * 1000);
  timer.unref();
  void safeTick();
}

/** Run a pass soon, e.g. right after someone queues a manual test. */
export function kickEngine(): void {
  if (config.engineEnabled) setTimeout(safeTick, 0).unref();
}

export function stopEngine(): void {
  if (timer) clearInterval(timer);
  timer = undefined;
}
