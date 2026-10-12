import { beforeAll, describe, expect, it } from 'vitest';
import { pool, withCompany } from '../src/db.js';
import { recordTestResult } from '../src/services.js';
import { login, makeCompany, makeUser, resetDb, type Client } from './helpers.js';

let a: Client, b: Client;
let serverA: number, jobA: number;
let companyA: number, companyB: number;

beforeAll(async () => {
  await resetDb();
  companyA = await makeCompany('alpha');
  companyB = await makeCompany('bravo');
  await makeUser(companyA, 'alice', 'company_admin');
  await makeUser(companyB, 'bob', 'company_admin');
  a = await login('alice');
  b = await login('bob');
  const s = await a.post('/api/servers', { hostname: 'web-01', os_type: 'linux', backup_methods: ['rsync'] });
  serverA = s.body.data.id;
  const j = await a.post('/api/backup-jobs', { server_id: serverA, backup_method: 'rsync', schedule: '0 2 * * *' });
  jobA = j.body.data.id;
  const t = await a.post(`/api/backup-jobs/${jobA}/test`, { test_type: 'synthetic_file' });
  await withCompany(companyA, (db) => recordTestResult(db, companyA, t.body.data.id, { success: false, failure_type: 'hash_mismatch', hash_original: 'a', hash_restored: 'b' }));
});

describe('tenant isolation (API)', () => {
  it('company B sees none of company A data', async () => {
    expect((await b.get('/api/servers')).body.data).toEqual([]);
    expect((await b.get('/api/backup-jobs')).body.data).toEqual([]);
    expect((await b.get('/api/tests')).body.data).toEqual([]);
    expect((await b.get('/api/test-failures')).body.data).toEqual([]);
    expect((await b.get('/api/audit')).body.data.every((r: any) => r.resource_type !== 'server')).toBe(true);
  });

  it('direct access to company A records by id is 404 for company B', async () => {
    expect((await b.get(`/api/servers/${serverA}`)).status).toBe(404);
    expect((await b.get(`/api/backup-jobs/${jobA}`)).status).toBe(404);
    expect((await b.patch(`/api/servers/${serverA}`, { notes: 'x' })).status).toBe(404);
    expect((await b.del(`/api/backup-jobs/${jobA}`)).status).toBe(404);
    expect((await b.post(`/api/backup-jobs/${jobA}/test`, {})).status).toBe(404);
    expect((await b.post('/api/test-failures/1/resolve', { resolution_type: 'known_issue', backup_usable: true, notes: 'x' })).status).toBe(404);
  });

  it('cannot create a job on another company\'s server', async () => {
    await b.post('/api/servers', { hostname: 'b-01', os_type: 'linux', backup_methods: ['rsync'] });
    const res = await b.post('/api/backup-jobs', { server_id: serverA, backup_method: 'rsync' });
    expect(res.status).toBe(404);
  });

  it('user list is limited to own company and cross-company user creation is refused', async () => {
    const users = (await a.get('/api/users')).body.data.map((u: any) => u.username);
    expect(users).toEqual(['alice']);
    const res = await a.post('/api/users', { username: 'mallory', email: 'm@x.io', role: 'company_admin', password: 'a-long-password-1', company_id: companyB });
    expect(res.status).toBe(403);
  });

  it('dashboard path for another company is 403', async () => {
    expect((await a.get(`/api/companies/${companyB}/dashboard`)).status).toBe(403);
    expect((await a.get(`/api/companies/${companyA}/dashboard`)).status).toBe(200);
  });

  it('same hostname is allowed in different companies', async () => {
    expect((await b.post('/api/servers', { hostname: 'web-01', os_type: 'linux' })).status).toBe(201);
  });
});

describe('row-level security (database)', () => {
  it('returns nothing when no company is set', async () => {
    for (const t of ['servers', 'backup_jobs', 'backup_test_executions', 'backup_test_failures', 'audit_trail', 'notifications']) {
      const { rows } = await pool.query(`SELECT COUNT(*)::int AS n FROM ${t}`);
      expect(rows[0].n, t).toBe(0);
    }
  });

  it('a missing WHERE clause still cannot cross tenants', async () => {
    const seenByB = await withCompany(companyB, (db) => db.query('SELECT hostname FROM servers'));
    expect(seenByB.rows.map((r) => r.hostname)).not.toContain('web-01-from-a');
    expect(seenByB.rowCount).toBe(2); // B's own two servers only
    const jobsSeenByB = await withCompany(companyB, (db) => db.query('SELECT id FROM backup_jobs'));
    expect(jobsSeenByB.rowCount).toBe(0);
  });

  it('refuses writes into another company', async () => {
    await expect(
      withCompany(companyB, (db) => db.query(`INSERT INTO servers (company_id, hostname, os_type) VALUES ($1, 'x', 'linux')`, [companyA])),
    ).rejects.toThrow(/row-level security/);
  });
});

describe('audit trail', () => {
  it('cannot be updated or deleted', async () => {
    await expect(withCompany(companyA, (db) => db.query(`UPDATE audit_trail SET action = 'x'`))).rejects.toThrow(/immutable/);
    await expect(withCompany(companyA, (db) => db.query(`DELETE FROM audit_trail`))).rejects.toThrow(/immutable/);
  });
});
