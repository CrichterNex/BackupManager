import { Router } from 'express';
import { z } from 'zod';
import { audit } from '../audit.js';
import { require_, session } from '../auth.js';
import { withCompany } from '../db.js';
import { badRequest, conflict, notFound } from '../errors.js';
import { idParam, orderBy, pageParams, pagination } from '../http.js';
import { technicianScope } from '../scope.js';
import { BACKUP_METHODS } from '../constants.js';

export const serversRouter = Router();

// Derived health used by the list filter and the dashboard.
const STATUS_SQL = `CASE
  WHEN s.last_backup_status = 'failed' OR s.last_test_status = 'failed' THEN 'failing'
  WHEN s.last_backup_status IN ('success', 'warning') AND s.last_test_status = 'passed' THEN 'healthy'
  WHEN s.last_backup_status IN ('success', 'warning') AND s.last_test_status IS NULL THEN 'pending_test'
  ELSE 'unknown' END`;

const COLUMNS = `s.id, s.hostname, s.ip_address, s.os_type, s.os_name, s.criticality, s.backup_methods,
  s.backup_location_path, s.retention_policy_days, s.last_backup_time, s.last_backup_status,
  s.last_test_time, s.last_test_status, s.notes, s.created_at, s.updated_at, ${STATUS_SQL} AS status`;

const query = z.object({
  os: z.enum(['linux', 'windows']).optional(),
  status: z.enum(['healthy', 'failing', 'pending_test', 'unknown']).optional(),
  criticality: z.enum(['critical', 'standard', 'non-critical']).optional(),
  search: z.string().max(100).optional(),
});

serversRouter.get('/', require_('servers:read'), async (req, res) => {
  const s = session(req);
  const q = query.parse(req.query);
  const { page, limit, offset } = pageParams(req);
  const params: unknown[] = [s.company_id];
  let where = 's.company_id = $1 AND s.active';
  if (q.os) { params.push(q.os); where += ` AND s.os_type = $${params.length}`; }
  if (q.criticality) { params.push(q.criticality); where += ` AND s.criticality = $${params.length}`; }
  if (q.search) { params.push(`%${q.search.replace(/[%_\\]/g, '\\$&')}%`); where += ` AND s.hostname ILIKE $${params.length}`; }
  if (q.status) { params.push(q.status); where += ` AND (${STATUS_SQL}) = $${params.length}`; }
  where += technicianScope(s, 's.id', params);
  const order = orderBy(req, { hostname: 's.hostname', status: 'status', last_backup: 's.last_backup_time', last_test: 's.last_test_time' }, 'hostname');

  const out = await withCompany(s.company_id, async (db) => {
    const total = await db.query(`SELECT COUNT(*)::int AS n FROM servers s WHERE ${where}`, params);
    const rows = await db.query(
      `SELECT ${COLUMNS} FROM servers s WHERE ${where} ORDER BY ${order}, s.id LIMIT ${limit} OFFSET ${offset}`,
      params,
    );
    return { rows: rows.rows, total: total.rows[0].n };
  });
  res.json({ success: true, data: out.rows, pagination: pagination(page, limit, out.total) });
});

serversRouter.get('/:id', require_('servers:read'), async (req, res) => {
  const s = session(req);
  const params: unknown[] = [idParam(req), s.company_id];
  const scope = technicianScope(s, 's.id', params);
  const server = await withCompany(s.company_id, async (db) => {
    const { rows } = await db.query(`SELECT ${COLUMNS} FROM servers s WHERE s.id = $1 AND s.company_id = $2 AND s.active${scope}`, params);
    if (!rows[0]) throw notFound('Server', 'SERVER_001');
    const assigned = await db.query(
      `SELECT u.id, u.username FROM server_assignments a JOIN users u ON u.id = a.user_id WHERE a.server_id = $1 ORDER BY u.username`,
      [rows[0].id],
    );
    return { ...rows[0], assigned_technicians: assigned.rows };
  });
  res.json({ success: true, data: server });
});

const methods = z.array(z.enum(BACKUP_METHODS)).max(BACKUP_METHODS.length);
const base = z.object({
  hostname: z.string().min(1).max(255).regex(/^[A-Za-z0-9]([A-Za-z0-9._-]*[A-Za-z0-9])?$/, 'Invalid hostname'),
  ip_address: z.union([z.ipv4(), z.ipv6()]).nullable().optional(),
  os_type: z.enum(['linux', 'windows']),
  os_name: z.string().max(100).nullable().optional(),
  criticality: z.enum(['critical', 'standard', 'non-critical']).default('standard'),
  backup_methods: methods.default([]),
  backup_location_path: z.string().max(512).nullable().optional(),
  retention_policy_days: z.number().int().positive().nullable().optional(),
  notes: z.string().max(5000).nullable().optional(),
});
const COLS = ['hostname', 'ip_address', 'os_type', 'os_name', 'criticality', 'backup_methods', 'backup_location_path', 'retention_policy_days', 'notes'] as const;

serversRouter.post('/', require_('servers:write'), async (req, res) => {
  const s = session(req);
  const body = base.parse(req.body);
  const server = await withCompany(s.company_id, async (db) => {
    const limit = await db.query('SELECT max_servers FROM companies WHERE id = $1', [s.company_id]);
    const max = limit.rows[0]?.max_servers;
    if (max != null) {
      const n = await db.query('SELECT COUNT(*)::int AS n FROM servers WHERE active');
      if (n.rows[0].n >= max) throw conflict('SERVER_004', `Server limit reached for your subscription (${max})`);
    }
    const dup = await db.query('SELECT 1 FROM servers WHERE active AND LOWER(hostname) = LOWER($1)', [body.hostname]);
    if (dup.rowCount) throw conflict('SERVER_002', 'Hostname already exists');
    const { rows } = await db.query(
      `INSERT INTO servers (company_id, ${COLS.join(', ')}) VALUES ($1, ${COLS.map((_, i) => `$${i + 2}`).join(', ')}) RETURNING id`,
      [s.company_id, ...COLS.map((c) => body[c] ?? null)],
    );
    await audit(db, req, { action: 'server_created', resource_type: 'server', resource_id: rows[0].id, details: { hostname: body.hostname } });
    return (await db.query(`SELECT ${COLUMNS} FROM servers s WHERE s.id = $1`, [rows[0].id])).rows[0];
  });
  res.status(201).json({ success: true, data: server });
});

serversRouter.patch('/:id', require_('servers:write'), async (req, res) => {
  const s = session(req);
  const id = idParam(req);
  const body = base.partial().refine((b) => Object.keys(b).length > 0, 'No fields to update').parse(req.body);
  const sets: string[] = [];
  const params: unknown[] = [];
  for (const c of COLS) {
    if (body[c] !== undefined) { params.push(body[c]); sets.push(`${c} = $${params.length}`); }
  }
  const server = await withCompany(s.company_id, async (db) => {
    if (body.hostname) {
      const dup = await db.query('SELECT 1 FROM servers WHERE active AND LOWER(hostname) = LOWER($1) AND id <> $2', [body.hostname, id]);
      if (dup.rowCount) throw conflict('SERVER_002', 'Hostname already exists');
    }
    params.push(id, s.company_id);
    const { rowCount } = await db.query(
      `UPDATE servers SET ${sets.join(', ')}, updated_at = NOW() WHERE id = $${params.length - 1} AND company_id = $${params.length} AND active`,
      params,
    );
    if (!rowCount) throw notFound('Server', 'SERVER_001');
    await audit(db, req, { action: 'server_updated', resource_type: 'server', resource_id: id, details: body });
    return (await db.query(`SELECT ${COLUMNS} FROM servers s WHERE s.id = $1`, [id])).rows[0];
  });
  res.json({ success: true, data: server });
});

// Servers are soft-deleted so backup and test history stays intact for compliance evidence.
serversRouter.delete('/:id', require_('servers:write'), async (req, res) => {
  const s = session(req);
  const id = idParam(req);
  await withCompany(s.company_id, async (db) => {
    const { rowCount } = await db.query('UPDATE servers SET active = false, updated_at = NOW() WHERE id = $1 AND company_id = $2 AND active', [id, s.company_id]);
    if (!rowCount) throw notFound('Server', 'SERVER_001');
    await db.query('UPDATE backup_jobs SET deleted_at = NOW() WHERE server_id = $1 AND deleted_at IS NULL', [id]);
    await db.query('DELETE FROM server_assignments WHERE server_id = $1', [id]);
    await audit(db, req, { action: 'server_deleted', resource_type: 'server', resource_id: id });
  });
  res.status(204).end();
});

serversRouter.put('/:id/assignments', require_('servers:write'), async (req, res) => {
  const s = session(req);
  const id = idParam(req);
  const { user_ids } = z.object({ user_ids: z.array(z.number().int().positive()).max(100) }).parse(req.body);
  const ids = [...new Set(user_ids)];
  const out = await withCompany(s.company_id, async (db) => {
    const server = await db.query('SELECT 1 FROM servers WHERE id = $1 AND active', [id]);
    if (!server.rowCount) throw notFound('Server', 'SERVER_001');
    if (ids.length) {
      const ok = await db.query(
        `SELECT id FROM users WHERE id = ANY($1) AND company_id = $2 AND role = 'company_technician' AND active`,
        [ids, s.company_id],
      );
      if (ok.rowCount !== ids.length) throw badRequest('All assignees must be active technicians in your company');
    }
    await db.query('DELETE FROM server_assignments WHERE server_id = $1', [id]);
    for (const uid of ids) {
      await db.query('INSERT INTO server_assignments (server_id, user_id, company_id) VALUES ($1, $2, $3)', [id, uid, s.company_id]);
    }
    await audit(db, req, { action: 'server_assignments_set', resource_type: 'server', resource_id: id, details: { user_ids: ids } });
    return ids;
  });
  res.json({ success: true, data: { server_id: id, user_ids: out } });
});
