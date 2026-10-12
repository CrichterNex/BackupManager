import { Router } from 'express';
import { z } from 'zod';
import { kickEngine } from '../engine/engine.js';
import { audit } from '../audit.js';
import { require_, session } from '../auth.js';
import { withCompany } from '../db.js';
import { badRequest, conflict, notFound } from '../errors.js';
import { idParam, orderBy, pageParams, pagination } from '../http.js';
import { technicianScope } from '../scope.js';
import { BACKUP_METHODS, TEST_TYPES } from '../constants.js';
import { notifyAdmins } from '../services.js';

export const jobsRouter = Router();

const COLUMNS = `j.id, j.server_id, s.hostname, j.backup_method, j.schedule, j.retention_days, j.incremental,
  j.status, j.status_message, j.size_gb, j.files_count, j.started_time, j.completed_time,
  j.recovery_point_date, j.test_status, j.created_at, j.updated_at`;
const FROM = 'backup_jobs j JOIN servers s ON s.id = j.server_id';

// Five-field cron: minute hour day-of-month month day-of-week.
const CRON_FIELD = /^(\*|\d+(-\d+)?)(\/\d+)?(,(\*|\d+(-\d+)?)(\/\d+)?)*$/;
const cron = z.string().trim().max(100).refine((v) => {
  const f = v.split(/\s+/);
  return f.length === 5 && f.every((x) => CRON_FIELD.test(x));
}, 'Must be a five-field cron expression, e.g. "0 2 * * *"');

const STATUSES = ['scheduled', 'running', 'success', 'warning', 'failed'] as const;

jobsRouter.get('/', require_('jobs:read'), async (req, res) => {
  const s = session(req);
  const q = z.object({
    status: z.enum(STATUSES).optional(),
    method: z.enum(BACKUP_METHODS).optional(),
    server_id: z.coerce.number().int().positive().optional(),
  }).parse(req.query);
  const { page, limit, offset } = pageParams(req);
  const params: unknown[] = [s.company_id];
  let where = 'j.company_id = $1 AND j.deleted_at IS NULL';
  if (q.status) { params.push(q.status); where += ` AND j.status = $${params.length}`; }
  if (q.method) { params.push(q.method); where += ` AND j.backup_method = $${params.length}`; }
  if (q.server_id) { params.push(q.server_id); where += ` AND j.server_id = $${params.length}`; }
  where += technicianScope(s, 'j.server_id', params);
  const order = orderBy(req, { created_at: 'j.created_at', status: 'j.status', completed: 'j.completed_time', hostname: 's.hostname' }, 'created_at');
  const out = await withCompany(s.company_id, async (db) => ({
    total: (await db.query(`SELECT COUNT(*)::int AS n FROM ${FROM} WHERE ${where}`, params)).rows[0].n,
    rows: (await db.query(`SELECT ${COLUMNS} FROM ${FROM} WHERE ${where} ORDER BY ${order}, j.id DESC LIMIT ${limit} OFFSET ${offset}`, params)).rows,
  }));
  res.json({ success: true, data: out.rows, pagination: pagination(page, limit, out.total) });
});

jobsRouter.get('/:id', require_('jobs:read'), async (req, res) => {
  const s = session(req);
  const params: unknown[] = [idParam(req), s.company_id];
  const scope = technicianScope(s, 'j.server_id', params);
  const job = await withCompany(s.company_id, async (db) => {
    const { rows } = await db.query(`SELECT ${COLUMNS} FROM ${FROM} WHERE j.id = $1 AND j.company_id = $2 AND j.deleted_at IS NULL${scope}`, params);
    if (!rows[0]) throw notFound('Backup job', 'BACKUP_001');
    return rows[0];
  });
  res.json({ success: true, data: job });
});

const createBody = z.object({
  server_id: z.number().int().positive(),
  backup_method: z.enum(BACKUP_METHODS),
  schedule: cron.optional(),
  retention_days: z.number().int().positive().max(36500).optional(),
  incremental: z.boolean().default(false),
});

jobsRouter.post('/', require_('jobs:write'), async (req, res) => {
  const s = session(req);
  const b = createBody.parse(req.body);
  const job = await withCompany(s.company_id, async (db) => {
    const server = (await db.query('SELECT backup_methods FROM servers WHERE id = $1 AND active', [b.server_id])).rows[0];
    if (!server) throw notFound('Server', 'SERVER_001');
    if (!server.backup_methods.includes(b.backup_method)) {
      throw badRequest(`Server is not configured for ${b.backup_method}. Enable it on the server first.`);
    }
    const { rows } = await db.query(
      `INSERT INTO backup_jobs (company_id, server_id, backup_method, schedule, retention_days, incremental, created_by)
       VALUES ($1,$2,$3,$4,$5,$6,$7) RETURNING id`,
      [s.company_id, b.server_id, b.backup_method, b.schedule ?? null, b.retention_days ?? null, b.incremental, s.user_id],
    );
    await audit(db, req, { action: 'backup_job_created', resource_type: 'backup_job', resource_id: rows[0].id, details: b });
    return (await db.query(`SELECT ${COLUMNS} FROM ${FROM} WHERE j.id = $1`, [rows[0].id])).rows[0];
  });
  res.status(201).json({ success: true, data: job });
});

const patchBody = z.object({
  schedule: cron.nullable(),
  retention_days: z.number().int().positive().max(36500).nullable(),
  incremental: z.boolean(),
  status: z.enum(STATUSES),
  status_message: z.string().max(2000).nullable(),
  size_gb: z.number().nonnegative().max(1e9).nullable(),
  files_count: z.number().int().nonnegative().nullable(),
  recovery_point_date: z.coerce.date().nullable(),
}).partial().refine((b) => Object.keys(b).length > 0, 'No fields to update');

jobsRouter.patch('/:id', require_('jobs:write'), async (req, res) => {
  const s = session(req);
  const id = idParam(req);
  const b = patchBody.parse(req.body);
  const sets: string[] = [];
  const params: unknown[] = [];
  for (const [k, v] of Object.entries(b)) { params.push(v); sets.push(`${k} = $${params.length}`); }
  const finished = b.status === 'success' || b.status === 'warning' || b.status === 'failed';
  if (b.status === 'running') sets.push('started_time = NOW()');
  if (finished) sets.push('completed_time = NOW()');
  const job = await withCompany(s.company_id, async (db) => {
    params.push(id, s.company_id);
    const { rows } = await db.query(
      `UPDATE backup_jobs SET ${sets.join(', ')}, updated_at = NOW() WHERE id = $${params.length - 1} AND company_id = $${params.length} AND deleted_at IS NULL RETURNING server_id`,
      params,
    );
    if (!rows[0]) throw notFound('Backup job', 'BACKUP_001');
    if (finished) {
      await db.query('UPDATE servers SET last_backup_time = NOW(), last_backup_status = $2, updated_at = NOW() WHERE id = $1', [rows[0].server_id, b.status]);
      if (b.status === 'failed') {
        await notifyAdmins(db, s.company_id, {
          event_type: 'backup_failed', subject: 'Backup failed', message: b.status_message ?? 'A backup job reported failure.',
          resource_type: 'backup_job', resource_id: id,
        });
      }
    }
    await audit(db, req, { action: 'backup_job_updated', resource_type: 'backup_job', resource_id: id, details: b });
    return (await db.query(`SELECT ${COLUMNS} FROM ${FROM} WHERE j.id = $1`, [id])).rows[0];
  });
  res.json({ success: true, data: job });
});

jobsRouter.delete('/:id', require_('jobs:write'), async (req, res) => {
  const s = session(req);
  const id = idParam(req);
  await withCompany(s.company_id, async (db) => {
    const { rowCount } = await db.query('UPDATE backup_jobs SET deleted_at = NOW() WHERE id = $1 AND company_id = $2 AND deleted_at IS NULL', [id, s.company_id]);
    if (!rowCount) throw notFound('Backup job', 'BACKUP_001');
    await audit(db, req, { action: 'backup_job_deleted', resource_type: 'backup_job', resource_id: id });
  });
  res.status(204).end();
});

// Queue a manual test. The Phase 3 engine picks pending executions up and records the result.
jobsRouter.post('/:id/test', require_('tests:run'), async (req, res) => {
  const s = session(req);
  const id = idParam(req);
  const { test_type } = z.object({ test_type: z.enum(TEST_TYPES).default('random_restore') }).parse(req.body ?? {});
  const test = await withCompany(s.company_id, async (db) => {
    const params: unknown[] = [id, s.company_id];
    const scope = technicianScope(s, 'j.server_id', params);
    const job = (await db.query(`SELECT j.id FROM backup_jobs j WHERE j.id = $1 AND j.company_id = $2 AND j.deleted_at IS NULL${scope}`, params)).rows[0];
    if (!job) throw notFound('Backup job', 'BACKUP_001');
    const open = await db.query(`SELECT 1 FROM backup_test_executions WHERE backup_job_id = $1 AND test_type = $2 AND test_status IN ('pending','running')`, [id, test_type]);
    if (open.rowCount) throw conflict('TEST_003', 'A test of this type is already queued or running for this job');
    const { rows } = await db.query(
      `INSERT INTO backup_test_executions (company_id, backup_job_id, test_type, executed_by) VALUES ($1,$2,$3,$4) RETURNING *`,
      [s.company_id, id, test_type, s.user_id],
    );
    await audit(db, req, { action: 'test_triggered', resource_type: 'test', resource_id: rows[0].id, details: { backup_job_id: id, test_type } });
    return rows[0];
  });
  kickEngine();
  res.status(202).json({ success: true, data: test });
});
