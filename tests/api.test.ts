import request from 'supertest';
import { beforeAll, describe, expect, it } from 'vitest';
import { pool, withCompany } from '../src/db.js';
import { healthScore } from '../src/routes/misc.js';
import { recordTestResult } from '../src/services.js';
import { app, login, makeCompany, makeUser, PASSWORD, resetDb, type Client } from './helpers.js';

let admin: Client, manager: Client, tech: Client, otherTech: Client;
let companyId: number, techId: number, otherTechId: number;
let serverId: number, assignedServer: number, jobId: number, assignedJob: number;

beforeAll(async () => {
  await resetDb();
  companyId = await makeCompany('acme');
  await makeUser(companyId, 'admin', 'company_admin');
  await makeUser(companyId, 'manager', 'company_manager');
  techId = await makeUser(companyId, 'tech', 'company_technician');
  otherTechId = await makeUser(companyId, 'tech2', 'company_technician');
  admin = await login('admin');
  manager = await login('manager');
  tech = await login('tech');
  otherTech = await login('tech2');

  serverId = (await admin.post('/api/servers', { hostname: 'files-01', os_type: 'windows', backup_methods: ['windows_manual_copy'] })).body.data.id;
  assignedServer = (await admin.post('/api/servers', { hostname: 'web-01', os_type: 'linux', backup_methods: ['linux_manual_copy', 'rsync'] })).body.data.id;
  await admin.put(`/api/servers/${assignedServer}/assignments`, { user_ids: [techId] });
  jobId = (await admin.post('/api/backup-jobs', { server_id: serverId, backup_method: 'windows_manual_copy', schedule: '0 2 * * *' })).body.data.id;
  assignedJob = (await admin.post('/api/backup-jobs', { server_id: assignedServer, backup_method: 'linux_manual_copy' })).body.data.id;
});

describe('authentication', () => {
  it('rejects wrong passwords and unknown users with the same message', async () => {
    const wrong = await request(app).post('/api/auth/login').send({ username: 'admin', password: 'nope-nope-nope' });
    const unknown = await request(app).post('/api/auth/login').send({ username: 'ghost', password: 'nope-nope-nope' });
    expect(wrong.status).toBe(401);
    expect(unknown.status).toBe(401);
    expect(wrong.body.error).toBe(unknown.body.error);
  });

  it('logs failed attempts to the audit trail', async () => {
    // The wrong-password attempt is attributed to the company; the unknown-user attempt has no tenant.
    const { rows } = await withCompany(companyId, (db) => db.query(`SELECT COUNT(*)::int AS n FROM audit_trail WHERE action = 'login_failed' AND success = false`));
    expect(rows[0].n).toBeGreaterThanOrEqual(1);
  });

  it('sets an HttpOnly SameSite=Strict cookie and accepts it', async () => {
    const res = await request(app).post('/api/auth/login').send({ username: 'admin', password: PASSWORD });
    const cookie = (res.headers['set-cookie'] as unknown as string[])[0]!;
    expect(cookie).toMatch(/auth_token=/);
    expect(cookie).toMatch(/HttpOnly/i);
    expect(cookie).toMatch(/SameSite=Strict/i);
    const me = await request(app).get('/api/me').set('Cookie', cookie);
    expect(me.body.data.username).toBe('admin');
    expect(res.body.redirect_to).toBe('/companies/acme/dashboard');
  });

  it('requires a token, and rejects a tampered one', async () => {
    expect((await request(app).get('/api/servers')).status).toBe(401);
    expect((await request(app).get('/api/servers').set('Authorization', 'Bearer abc.def.ghi')).status).toBe(401);
  });

  it('applies deactivation immediately to existing tokens', async () => {
    await makeUser(companyId, 'temp', 'company_manager');
    const t = await login('temp');
    expect((await t.get('/api/servers')).status).toBe(200);
    await pool.query(`UPDATE users SET active = false WHERE username = 'temp'`);
    expect((await t.get('/api/servers')).status).toBe(401);
    expect((await request(app).post('/api/auth/login').send({ username: 'temp', password: PASSWORD })).status).toBe(401);
  });

  it('changes password only with the correct current one', async () => {
    await makeUser(companyId, 'pw', 'company_manager');
    const c = await login('pw');
    expect((await c.post('/api/me/change-password', { current_password: 'wrong-wrong-wrong', new_password: 'another-long-pass-1' })).status).toBe(401);
    expect((await c.post('/api/me/change-password', { current_password: PASSWORD, new_password: 'short' })).status).toBe(400);
    expect((await c.post('/api/me/change-password', { current_password: PASSWORD, new_password: 'another-long-pass-1' })).status).toBe(200);
    expect((await request(app).post('/api/auth/login').send({ username: 'pw', password: 'another-long-pass-1' })).status).toBe(200);
  });

  it('never returns password hashes', async () => {
    const res = await admin.get('/api/users');
    expect(JSON.stringify(res.body)).not.toMatch(/password_hash|\$2[aby]\$/);
  });
});

describe('role permissions', () => {
  it('manager is read-only', async () => {
    expect((await manager.get('/api/servers')).status).toBe(200);
    expect((await manager.get('/api/backup-jobs')).status).toBe(200);
    expect((await manager.post('/api/servers', { hostname: 'x', os_type: 'linux' })).status).toBe(403);
    expect((await manager.post('/api/backup-jobs', { server_id: serverId, backup_method: 'windows_manual_copy' })).status).toBe(403);
    expect((await manager.post(`/api/backup-jobs/${jobId}/test`, {})).status).toBe(403);
    expect((await manager.get('/api/users')).status).toBe(403);
    expect((await manager.get('/api/audit')).status).toBe(403);
  });

  it('technician cannot configure anything', async () => {
    expect((await tech.post('/api/servers', { hostname: 'x', os_type: 'linux' })).status).toBe(403);
    expect((await tech.post('/api/backup-jobs', { server_id: assignedServer, backup_method: 'rsync' })).status).toBe(403);
    expect((await tech.del(`/api/servers/${assignedServer}`)).status).toBe(403);
    expect((await tech.get('/api/users')).status).toBe(403);
  });

  it('technician sees only assigned servers and their jobs', async () => {
    expect((await tech.get('/api/servers')).body.data.map((s: any) => s.hostname)).toEqual(['web-01']);
    expect((await otherTech.get('/api/servers')).body.data).toEqual([]);
    expect((await tech.get(`/api/servers/${serverId}`)).status).toBe(404);
    expect((await tech.get('/api/backup-jobs')).body.data.map((j: any) => j.id)).toEqual([assignedJob]);
    expect((await tech.get(`/api/backup-jobs/${jobId}`)).status).toBe(404);
    expect((await tech.post(`/api/backup-jobs/${jobId}/test`, {})).status).toBe(404);
    expect((await tech.post(`/api/backup-jobs/${assignedJob}/test`, {})).status).toBe(202);
  });

  it('only admins can manage users, and cannot demote themselves', async () => {
    const me = (await admin.get('/api/me')).body.data.id;
    expect((await admin.patch(`/api/users/${me}`, { role: 'company_manager' })).status).toBe(400);
    expect((await admin.patch(`/api/users/${me}`, { active: false })).status).toBe(400);
    const made = await admin.post('/api/users', { username: 'newbie', email: 'n@x.io', role: 'company_technician', password: 'a-long-password-1' });
    expect(made.status).toBe(201);
    expect((await admin.post('/api/users', { username: 'NEWBIE', email: 'n@x.io', role: 'company_technician', password: 'a-long-password-1' })).status).toBe(409);
    expect((await admin.post('/api/users', { username: 'weak', email: 'w@x.io', role: 'company_manager', password: 'short' })).status).toBe(400);
  });

  it('assignments only accept technicians from the same company', async () => {
    const mgr = (await manager.get('/api/me')).body.data.id;
    expect((await admin.put(`/api/servers/${serverId}/assignments`, { user_ids: [mgr] })).status).toBe(400);
    expect((await admin.put(`/api/servers/${serverId}/assignments`, { user_ids: [999999] })).status).toBe(400);
  });
});

describe('servers and backup jobs', () => {
  it('validates input', async () => {
    expect((await admin.post('/api/servers', { hostname: 'bad host!', os_type: 'linux' })).status).toBe(400);
    expect((await admin.post('/api/servers', { hostname: 'ok', os_type: 'solaris' })).status).toBe(400);
    expect((await admin.post('/api/servers', { hostname: 'ok2', os_type: 'linux', ip_address: 'not-an-ip' })).status).toBe(400);
    expect((await admin.post('/api/servers', { hostname: 'FILES-01', os_type: 'linux' })).status).toBe(409);
  });

  it('rejects jobs for methods the server is not configured for, and bad cron', async () => {
    expect((await admin.post('/api/backup-jobs', { server_id: serverId, backup_method: 'veeam' })).status).toBe(400);
    expect((await admin.post('/api/backup-jobs', { server_id: serverId, backup_method: 'windows_manual_copy', schedule: 'every day' })).status).toBe(400);
    expect((await admin.post('/api/backup-jobs', { server_id: serverId, backup_method: 'windows_manual_copy', schedule: '0 2 * *' })).status).toBe(400);
  });

  it('records job completion on the server and notifies admins on failure', async () => {
    await admin.patch(`/api/backup-jobs/${jobId}`, { status: 'running' });
    await admin.patch(`/api/backup-jobs/${jobId}`, { status: 'failed', status_message: 'disk full' });
    const server = (await admin.get(`/api/servers/${serverId}`)).body.data;
    expect(server.last_backup_status).toBe('failed');
    expect(server.status).toBe('failing');
    const n = (await admin.get('/api/notifications?unread=true')).body;
    expect(n.data.some((x: any) => x.event_type === 'backup_failed' && x.message === 'disk full')).toBe(true);
    expect((await manager.get('/api/notifications')).body.data.length).toBe(0);
  });

  it('enforces the subscription server limit', async () => {
    const small = await makeCompany('small', { max_servers: 1 });
    await makeUser(small, 'smalladmin', 'company_admin');
    const c = await login('smalladmin');
    expect((await c.post('/api/servers', { hostname: 'one', os_type: 'linux' })).status).toBe(201);
    expect((await c.post('/api/servers', { hostname: 'two', os_type: 'linux' })).status).toBe(409);
  });

  it('soft-deletes servers, keeping history, and frees the hostname', async () => {
    const id = (await admin.post('/api/servers', { hostname: 'temp-box', os_type: 'linux', backup_methods: ['rsync'] })).body.data.id;
    await admin.post('/api/backup-jobs', { server_id: id, backup_method: 'rsync' });
    expect((await admin.del(`/api/servers/${id}`)).status).toBe(204);
    expect((await admin.get(`/api/servers/${id}`)).status).toBe(404);
    const { rows } = await withCompany(companyId, (db) => db.query('SELECT active FROM servers WHERE id = $1', [id]));
    expect(rows[0].active).toBe(false);
    expect((await admin.post('/api/servers', { hostname: 'temp-box', os_type: 'linux' })).status).toBe(201);
  });

  it('paginates, filters and sorts safely', async () => {
    const res = await admin.get('/api/servers?limit=1&page=2&sort_by=hostname&sort_order=asc&os=linux');
    expect(res.status).toBe(200);
    expect(res.body.pagination.limit).toBe(1);
    expect((await admin.get('/api/servers?sort_by=id;DROP TABLE servers')).status).toBe(200);
    expect((await admin.get('/api/servers?search=%25')).body.data).toEqual([]);
    expect((await admin.get('/api/servers?limit=5000')).status).toBe(400);
  });
});

describe('test failure workflow', () => {
  let failureId: number, testId: number;

  beforeAll(async () => {
    testId = (await admin.post(`/api/backup-jobs/${jobId}/test`, { test_type: 'random_restore' })).body.data.id;
    await withCompany(companyId, (db) => recordTestResult(db, companyId, testId, {
      success: false, failure_type: 'hash_mismatch', severity: 'critical', hash_original: 'aaa', hash_restored: 'bbb', failed_item: '/data/report.xlsx',
    }));
    failureId = (await admin.get('/api/test-failures?resolved=false')).body.data[0].id;
  });

  it('prevents queueing a duplicate open test of the same type', async () => {
    const first = (await admin.post(`/api/backup-jobs/${assignedJob}/test`, { test_type: 'file_permissions' }));
    expect(first.status).toBe(202);
    expect((await admin.post(`/api/backup-jobs/${assignedJob}/test`, { test_type: 'file_permissions' })).status).toBe(409);
  });

  it('records the failure, flags the server and the job, and alerts admins', async () => {
    const t = (await admin.get(`/api/tests/${testId}`)).body.data;
    expect(t.test_status).toBe('failed');
    expect(t.hash_match).toBe(false);
    expect(t.failures).toHaveLength(1);
    expect((await admin.get(`/api/servers/${serverId}`)).body.data.last_test_status).toBe('failed');
    expect((await admin.get(`/api/backup-jobs/${jobId}`)).body.data.test_status).toBe('failed');
    expect((await admin.get('/api/notifications')).body.data.some((n: any) => n.event_type === 'test_failed')).toBe(true);
  });

  it('cannot record a result twice', async () => {
    await expect(withCompany(companyId, (db) => recordTestResult(db, companyId, testId, { success: true }))).rejects.toThrow(/not pending/);
  });

  it('requires notes and a valid resolution, and managers cannot resolve', async () => {
    expect((await admin.post(`/api/test-failures/${failureId}/resolve`, { resolution_type: 'known_issue', backup_usable: true, notes: '  ' })).status).toBe(400);
    expect((await admin.post(`/api/test-failures/${failureId}/resolve`, { resolution_type: 'made_up', backup_usable: true, notes: 'x' })).status).toBe(400);
    expect((await manager.post(`/api/test-failures/${failureId}/resolve`, { resolution_type: 'known_issue', backup_usable: true, notes: 'x' })).status).toBe(403);
  });

  it('resolves once, attributes it, and logs it', async () => {
    const ok = await admin.post(`/api/test-failures/${failureId}/resolve`, { resolution_type: 'retest_passed', backup_usable: true, notes: 'Retest matched' });
    expect(ok.status).toBe(200);
    expect(ok.body.data.resolved).toBe(true);
    expect(ok.body.data.resolved_by).toBeTruthy();
    expect((await admin.post(`/api/test-failures/${failureId}/resolve`, { resolution_type: 'known_issue', backup_usable: true, notes: 'again' })).status).toBe(409);
    expect((await admin.get('/api/audit?action=failure_resolved')).body.data).toHaveLength(1);
  });

  it('technicians cannot resolve failures on servers they are not assigned to', async () => {
    const id = (await admin.post(`/api/backup-jobs/${jobId}/test`, { test_type: 'recovery_point' })).body.data.id;
    await withCompany(companyId, (db) => recordTestResult(db, companyId, id, { success: false, error_message: 'timeout', failure_type: 'timeout' }));
    const open = (await admin.get('/api/test-failures?resolved=false')).body.data[0].id;
    expect((await tech.post(`/api/test-failures/${open}/resolve`, { resolution_type: 'known_issue', backup_usable: true, notes: 'x' })).status).toBe(404);
  });
});

describe('dashboard and audit', () => {
  it('computes the health score without defaulting empty data to 100', () => {
    expect(healthScore(null, null)).toBeNull();
    expect(healthScore(1, null)).toBe(100);
    expect(healthScore(null, 0.5)).toBe(50);
    expect(healthScore(1, 0.5)).toBe(75);
  });

  it('reports company metrics, scoped for technicians', async () => {
    const d = (await admin.get('/api/dashboard')).body.data;
    expect(d.company.slug).toBe('acme');
    expect(d.servers.total).toBe(3);
    expect(d.unresolved_failures).toBeGreaterThanOrEqual(1);
    const t = (await tech.get('/api/dashboard')).body.data;
    expect(t.servers.total).toBe(1);
    expect(t.unresolved_failures).toBe(0);
  });

  it('records who did what, and unauthenticated routes are not found', async () => {
    const rows = (await admin.get('/api/audit?action=server_created')).body.data;
    expect(rows.length).toBeGreaterThanOrEqual(2);
    expect(rows[0].username).toBe('admin');
    expect((await request(app).get('/api/nope')).status).toBe(401);
    expect((await admin.get('/api/nope')).status).toBe(404);
  });
});
