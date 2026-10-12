import { mkdir, mkdtemp, readdir, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { pool, withCompany } from '../src/db.js';
import { escalateFailures, runDueTests, scheduleDueTests } from '../src/engine/engine.js';
import { runRandomRestore } from '../src/engine/runners.js';
import { app, login, makeCompany, makeUser, resetDb, type Client } from './helpers.js';

let admin: Client;
let companyId: number;
let root: string;
const staging = path.join(os.tmpdir(), 'bms-test-staging');

async function makeJob(hostname: string, method: string, backupPath: string | null, status = 'success') {
  const server = (await admin.post('/api/servers', { hostname, os_type: 'linux', backup_methods: [method], backup_location_path: backupPath ?? undefined })).body.data.id;
  const job = (await admin.post('/api/backup-jobs', { server_id: server, backup_method: method })).body.data.id;
  await pool.query('UPDATE backup_jobs SET status = $2 WHERE id = $1', [job, status]);
  return { server, job };
}

const tests = (jobId: number) => pool.query('SELECT * FROM backup_test_executions WHERE backup_job_id = $1 ORDER BY id', [jobId]).then((r) => r.rows);

beforeAll(async () => {
  void app;
  await resetDb();
  companyId = await makeCompany('engine-co');
  await makeUser(companyId, 'admin', 'company_admin');
  await makeUser(companyId, 'mgr', 'company_manager');
  admin = await login('admin');
  root = await mkdtemp(path.join(os.tmpdir(), 'bms-engine-'));
  await mkdir(staging, { recursive: true });
});

afterAll(async () => {
  await rm(root, { recursive: true, force: true });
  await rm(staging, { recursive: true, force: true });
});

describe('random restore runner', () => {
  it('passes on an intact backup and removes its staging copy', async () => {
    const dir = path.join(root, 'ok');
    await mkdir(path.join(dir, 'sub'), { recursive: true });
    await writeFile(path.join(dir, 'a.txt'), 'alpha');
    await writeFile(path.join(dir, 'sub', 'b.txt'), 'beta');
    const r = await runRandomRestore(dir, { stagingDir: staging });
    expect(r.success).toBe(true);
    expect(r.hash_original).toBe(r.hash_restored);
    expect(await readdir(staging)).toEqual([]);
  });

  it('fails with file_missing for an empty or missing location', async () => {
    const empty = path.join(root, 'empty');
    await mkdir(empty);
    expect(await runRandomRestore(empty, { stagingDir: staging })).toMatchObject({ success: false, failure_type: 'file_missing' });
    expect(await runRandomRestore(path.join(root, 'nope'), { stagingDir: staging })).toMatchObject({ success: false, failure_type: 'file_missing' });
  });
});

describe('scheduling and execution', () => {
  it('queues only filesystem-method jobs with a successful backup, once per period', async () => {
    const good = await makeJob('good-01', 'rsync', path.join(root, 'ok'));
    const failed = await makeJob('failed-01', 'rsync', path.join(root, 'ok'), 'failed');
    const vendor = await makeJob('veeam-01', 'veeam', null);
    expect(await scheduleDueTests()).toBe(1);
    expect(await scheduleDueTests()).toBe(0);
    expect(await tests(good.job)).toHaveLength(1);
    expect(await tests(failed.job)).toHaveLength(0);
    expect(await tests(vendor.job)).toHaveLength(0);
  });

  it('records a pass and updates the server and job', async () => {
    await runDueTests(companyId);
    const { rows } = await pool.query(`SELECT t.test_status, t.hash_match, s.last_test_status FROM backup_test_executions t
      JOIN backup_jobs j ON j.id = t.backup_job_id JOIN servers s ON s.id = j.server_id WHERE s.hostname = 'good-01'`);
    expect(rows[0]).toMatchObject({ test_status: 'success', hash_match: true, last_test_status: 'passed' });
  });

  it('skips unsupported tests instead of passing them', async () => {
    const { job } = await makeJob('veeam-02', 'veeam', null);
    const res = await admin.post(`/api/backup-jobs/${job}/test`, { test_type: 'random_restore' });
    expect(res.status).toBe(202);
    await runDueTests(companyId);
    const [t] = await tests(job);
    expect(t.test_status).toBe('skipped');
    expect(t.error_message).toMatch(/not available for veeam/);
  });

  it('retries a failure quietly, then records one failure and alerts after the last attempt', async () => {
    const { job } = await makeJob('broken-01', 'linux_manual_copy', path.join(root, 'missing'));
    await admin.post(`/api/backup-jobs/${job}/test`, { test_type: 'random_restore' });
    for (let attempt = 1; attempt <= 3; attempt++) {
      await runDueTests(companyId);
      const rows = await tests(job);
      expect(rows).toHaveLength(Math.min(attempt + 1, 3));
      const failures = await pool.query('SELECT * FROM backup_test_failures f JOIN backup_test_executions t ON t.id = f.test_execution_id WHERE t.backup_job_id = $1', [job]);
      expect(failures.rows).toHaveLength(attempt === 3 ? 1 : 0);
      if (attempt < 3) await pool.query(`UPDATE backup_test_executions SET scheduled_time = NOW() WHERE backup_job_id = $1 AND test_status = 'pending'`, [job]);
    }
    const alerts = await pool.query(`SELECT message FROM notifications WHERE event_type = 'test_failed'`);
    expect(alerts.rows).toHaveLength(1);
    expect(alerts.rows[0].message).toMatch(/failed 3 times/);
  });

  it('escalates unresolved failures to managers once per level', async () => {
    await pool.query(`UPDATE backup_test_failures SET created_at = NOW() - INTERVAL '5 hours'`);
    expect(await escalateFailures(companyId)).toBe(1);
    expect(await escalateFailures(companyId)).toBe(0);
    await pool.query(`UPDATE backup_test_failures SET created_at = NOW() - INTERVAL '9 hours'`);
    expect(await escalateFailures(companyId)).toBe(1);
    const n = await withCompany(companyId, (db) => db.query(`SELECT u.role FROM notifications n JOIN users u ON u.id = n.user_id WHERE n.event_type = 'failure_escalated'`));
    expect(n.rows.map((r) => r.role).sort()).toEqual(['company_admin', 'company_admin', 'company_manager', 'company_manager']);
  });
});
