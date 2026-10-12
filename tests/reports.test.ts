import { beforeAll, describe, expect, it } from 'vitest';
import { pool, withCompany } from '../src/db.js';
import { generateScheduledReports } from '../src/reports/service.js';
import { recordTestResult } from '../src/services.js';
import { login, makeCompany, q, makeUser, resetDb, type Client } from './helpers.js';

let admin: Client, manager: Client, tech: Client, other: Client;
let companyId: number, otherId: number;
let serverA: number, serverB: number;

const DAY = 86_400_000;
const period = () => ({ period_start: new Date(Date.now() - 30 * DAY).toISOString(), period_end: new Date().toISOString() });

async function finishedJob(serverId: number, status: string, daysAgo: number) {
  const done = new Date(Date.now() - daysAgo * DAY);
  await q(companyId, 
    `INSERT INTO backup_jobs (company_id, server_id, backup_method, status, started_time, completed_time, size_gb)
     VALUES ($1,$2,'rsync',$3,$4,$5,10)`, [companyId, serverId, status, new Date(done.getTime() - 2 * 3600_000), done]);
}

async function runTest(jobId: number, result: Parameters<typeof recordTestResult>[3]) {
  const t = await admin.post(`/api/backup-jobs/${jobId}/test`, { test_type: 'random_restore' });
  await withCompany(companyId, (db) => recordTestResult(db, companyId, t.body.data.id, result));
}

beforeAll(async () => {
  await resetDb();
  companyId = await makeCompany('reportco');
  otherId = await makeCompany('otherco');
  await makeUser(companyId, 'admin', 'company_admin');
  await makeUser(companyId, 'manager', 'company_manager');
  await makeUser(companyId, 'tech', 'company_technician');
  await makeUser(otherId, 'other', 'company_admin');
  admin = await login('admin');
  manager = await login('manager');
  tech = await login('tech');
  other = await login('other');

  serverA = (await admin.post('/api/servers', { hostname: 'web-01', os_type: 'linux', backup_methods: ['rsync'] })).body.data.id;
  serverB = (await admin.post('/api/servers', { hostname: 'db-01', os_type: 'linux', backup_methods: ['rsync'] })).body.data.id;
  const jobA = (await admin.post('/api/backup-jobs', { server_id: serverA, backup_method: 'rsync' })).body.data.id;
  const jobB = (await admin.post('/api/backup-jobs', { server_id: serverB, backup_method: 'rsync' })).body.data.id;
  for (const d of [1, 2, 3]) await finishedJob(serverA, 'success', d);
  await finishedJob(serverB, 'failed', 1);
  await q(companyId, `UPDATE servers SET last_backup_time = NOW() - INTERVAL '2 hours', last_backup_status = 'success' WHERE id = $1`, [serverA]);
  await runTest(jobA, { success: true, hash_algorithm: 'sha256', hash_original: 'aa', hash_restored: 'aa' });
  await runTest(jobA, { success: true, hash_algorithm: 'sha256', hash_original: 'bb', hash_restored: 'bb' });
  await runTest(jobB, { success: false, failure_type: 'hash_mismatch', severity: 'critical', hash_original: 'cc', hash_restored: 'dd', error_message: 'mismatch' });
});

describe('compliance report', () => {
  it('computes figures from recorded data and rates itself against the targets', async () => {
    const res = await admin.post('/api/reports', { report_type: 'compliance', ...period() });
    expect(res.status).toBe(201);
    const d = (await admin.get(`/api/reports/${res.body.data.id}`)).body.data.data;
    expect(d.backups).toMatchObject({ finished: 4, success: 3, failed: 1, success_rate_pct: 75, avg_duration_seconds: 7200 });
    expect(d.tests).toMatchObject({ run: 3, passed: 2, failed: 1, pass_rate_pct: 66.7, servers_tested: 2, coverage_pct: 100, hash_mismatches: 1 });
    expect(d.failures.open_now).toMatchObject({ critical: 1, high: 0 });
    expect(d.certification.level).toBe('non_compliant');
    expect(d.not_measured.length).toBeGreaterThan(0);
  });

  it('reports insufficient data for an empty period instead of a pass', async () => {
    const res = await admin.post('/api/reports', {
      report_type: 'compliance',
      period_start: new Date(Date.now() - 400 * DAY).toISOString(),
      period_end: new Date(Date.now() - 370 * DAY).toISOString(),
    });
    const d = (await admin.get(`/api/reports/${res.body.data.id}`)).body.data.data;
    expect(d.certification.level).toBe('insufficient_data');
    expect(d.backups.success_rate_pct).toBeNull();
  });

  it('keeps an archived report fixed after the data changes', async () => {
    const id = (await admin.post('/api/reports', { report_type: 'compliance', ...period() })).body.data.id;
    await q(companyId, `UPDATE backup_jobs SET status = 'failed'`);
    const d = (await admin.get(`/api/reports/${id}`)).body.data.data;
    expect(d.backups.success).toBe(3);
    await q(companyId, `UPDATE backup_jobs SET status = 'success' WHERE server_id = $1`, [serverA]);
  });
});

describe('other report types', () => {
  it('SLA report marks each target met, missed or no data', async () => {
    const id = (await admin.post('/api/reports', { report_type: 'sla', ...period(), targets: { backup_success_pct: 70 } })).body.data.id;
    const d = (await admin.get(`/api/reports/${id}`)).body.data.data;
    expect(d.metrics.map((m: { status: string }) => m.status)).toEqual(['met', 'met', 'missed']);
    expect(d.not_measured).toContain('RTO and RPO actuals');
  });

  it('security report counts users and audited activity', async () => {
    const id = (await admin.post('/api/reports', { report_type: 'security', ...period() })).body.data.id;
    const d = (await admin.get(`/api/reports/${id}`)).body.data.data;
    expect(d.users.active_admins).toBe(1);
    expect(d.sign_ins.successful).toBeGreaterThanOrEqual(3);
    expect(d.audit.entries).toBeGreaterThan(0);
  });

  it('DR report flags servers that are not ready and says why', async () => {
    const id = (await admin.post('/api/reports', { report_type: 'dr_readiness' })).body.data.id;
    const d = (await admin.get(`/api/reports/${id}`)).body.data.data;
    const byName = Object.fromEntries(d.servers.map((s: { hostname: string }) => [s.hostname, s]));
    expect(byName['web-01'].ready).toBe(true);
    expect(byName['db-01'].ready).toBe(false);
    expect(byName['db-01'].reasons.join()).toMatch(/No backup recorded/);
    expect(d.summary).toMatchObject({ total: 2, ready: 1, status: 'not_ready' });
    expect(d.last_full_recovery_test).toBeNull();
  });
});

describe('access and safety', () => {
  it('lets managers read and generate, but not technicians', async () => {
    expect((await manager.post('/api/reports', { report_type: 'security', ...period() })).status).toBe(201);
    expect((await manager.get('/api/reports')).status).toBe(200);
    expect((await tech.get('/api/reports')).status).toBe(403);
    expect((await tech.post('/api/reports', { report_type: 'dr_readiness' })).status).toBe(403);
  });

  it('never shows another company a report', async () => {
    const id = (await admin.post('/api/reports', { report_type: 'dr_readiness' })).body.data.id;
    expect((await other.get(`/api/reports/${id}`)).status).toBe(404);
    expect((await other.get(`/api/reports/${id}/view`)).status).toBe(404);
    expect((await other.get('/api/reports')).body.data).toHaveLength(0);
  });

  it('rejects bad periods', async () => {
    expect((await admin.post('/api/reports', { report_type: 'compliance' })).status).toBe(400);
    expect((await admin.post('/api/reports', { report_type: 'compliance', period_start: new Date(Date.now() - 400 * DAY).toISOString(), period_end: new Date().toISOString() })).status).toBe(400);
    expect((await admin.post('/api/reports', { report_type: 'compliance', period_start: new Date().toISOString(), period_end: new Date(Date.now() - DAY).toISOString() })).status).toBe(400);
  });

  it('escapes stored text in the printable view', async () => {
    await pool.query(`UPDATE companies SET name = '<img src=x onerror=alert(1)>' WHERE id = $1`, [companyId]);
    const id = (await admin.post('/api/reports', { report_type: 'compliance', ...period() })).body.data.id;
    const res = await admin.get(`/api/reports/${id}/view`);
    expect(res.status).toBe(200);
    expect(res.headers['content-type']).toMatch(/html/);
    expect(res.text).not.toContain('<img src=x');
    expect(res.text).toContain('&lt;img src=x');
    expect(res.text).toContain('Not measured by BMS');
  });

  it('audits report generation', async () => {
    const { rows } = await q(companyId, `SELECT 1 FROM audit_trail WHERE action = 'report_generated' AND company_id = $1`, [companyId]);
    expect(rows.length).toBeGreaterThan(0);
  });
});

describe('scheduled generation', () => {
  it('archives last month and last quarter once, and only for companies that existed then', async () => {
    await pool.query('TRUNCATE reports');
    await pool.query(`UPDATE companies SET created_at = '2020-01-01' WHERE id = $1`, [companyId]);
    await pool.query(`UPDATE companies SET created_at = '2026-03-02T00:00:00Z' WHERE id = $1`, [otherId]);
    const now = new Date('2026-03-15T10:00:00Z');
    expect(await generateScheduledReports(now)).toBe(2);
    expect(await generateScheduledReports(now)).toBe(0);
    const rowsOf = async (id: number) => (await q(id, `SELECT company_id, report_type, period_start::date::text AS s FROM reports ORDER BY report_type`)).rows;
    const rows = [...(await rowsOf(companyId)), ...(await rowsOf(otherId))];
    expect(rows.filter((r) => r.company_id === companyId)).toEqual([
      { company_id: companyId, report_type: 'compliance', s: '2026-02-01' },
      { company_id: companyId, report_type: 'sla', s: '2025-10-01' },
    ]);
    expect(rows.filter((r) => r.company_id === otherId)).toHaveLength(0);
  });
});
