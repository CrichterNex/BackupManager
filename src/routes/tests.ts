import { Router } from 'express';
import { z } from 'zod';
import { audit } from '../audit.js';
import { require_, session } from '../auth.js';
import { withCompany } from '../db.js';
import { conflict, notFound } from '../errors.js';
import { idParam, orderBy, pageParams, pagination } from '../http.js';
import { technicianScope } from '../scope.js';
import { TEST_TYPES } from '../constants.js';

export const testsRouter = Router();

const TEST_COLS = `t.id, t.backup_job_id, s.hostname, t.test_type, t.test_status, t.hash_algorithm, t.hash_original,
  t.hash_restored, t.hash_match, t.scheduled_time, t.started_time, t.completed_time, t.duration_seconds,
  t.error_message, t.executed_by, t.created_at`;
const TEST_FROM = 'backup_test_executions t JOIN backup_jobs j ON j.id = t.backup_job_id JOIN servers s ON s.id = j.server_id';

testsRouter.get('/tests', require_('tests:read'), async (req, res) => {
  const s = session(req);
  const q = z.object({
    status: z.enum(['pending', 'running', 'success', 'failed', 'skipped', 'cancelled']).optional(),
    test_type: z.enum(TEST_TYPES).optional(),
    backup_job_id: z.coerce.number().int().positive().optional(),
    days: z.coerce.number().int().min(1).max(3650).optional(),
  }).parse(req.query);
  const { page, limit, offset } = pageParams(req);
  const params: unknown[] = [s.company_id];
  let where = 't.company_id = $1';
  if (q.status) { params.push(q.status); where += ` AND t.test_status = $${params.length}`; }
  if (q.test_type) { params.push(q.test_type); where += ` AND t.test_type = $${params.length}`; }
  if (q.backup_job_id) { params.push(q.backup_job_id); where += ` AND t.backup_job_id = $${params.length}`; }
  if (q.days) { params.push(q.days); where += ` AND t.created_at >= NOW() - ($${params.length} || ' days')::interval`; }
  where += technicianScope(s, 'j.server_id', params);
  const order = orderBy(req, { created_at: 't.created_at', duration_seconds: 't.duration_seconds', status: 't.test_status' }, 'created_at');
  const out = await withCompany(s.company_id, async (db) => ({
    total: (await db.query(`SELECT COUNT(*)::int AS n FROM ${TEST_FROM} WHERE ${where}`, params)).rows[0].n,
    rows: (await db.query(`SELECT ${TEST_COLS} FROM ${TEST_FROM} WHERE ${where} ORDER BY ${order}, t.id DESC LIMIT ${limit} OFFSET ${offset}`, params)).rows,
  }));
  res.json({ success: true, data: out.rows, pagination: pagination(page, limit, out.total) });
});

testsRouter.get('/tests/:id', require_('tests:read'), async (req, res) => {
  const s = session(req);
  const params: unknown[] = [idParam(req), s.company_id];
  const scope = technicianScope(s, 'j.server_id', params);
  const test = await withCompany(s.company_id, async (db) => {
    const { rows } = await db.query(`SELECT ${TEST_COLS} FROM ${TEST_FROM} WHERE t.id = $1 AND t.company_id = $2${scope}`, params);
    if (!rows[0]) throw notFound('Test', 'TEST_001');
    const failures = await db.query('SELECT * FROM backup_test_failures WHERE test_execution_id = $1 ORDER BY id', [rows[0].id]);
    return { ...rows[0], failures: failures.rows };
  });
  res.json({ success: true, data: test });
});

const FAIL_COLS = `f.id, f.test_execution_id, t.test_type, s.hostname, f.failure_type, f.severity, f.failed_item,
  f.expected_value, f.actual_value, f.error_message, f.resolved, f.resolution_type, f.resolution_notes,
  f.resolved_by, f.resolved_at, f.backup_usable, f.created_at`;
const FAIL_FROM = `backup_test_failures f JOIN backup_test_executions t ON t.id = f.test_execution_id
  JOIN backup_jobs j ON j.id = t.backup_job_id JOIN servers s ON s.id = j.server_id`;

testsRouter.get('/test-failures', require_('tests:read'), async (req, res) => {
  const s = session(req);
  const q = z.object({
    resolved: z.enum(['true', 'false']).optional(),
    severity: z.enum(['critical', 'high', 'medium', 'low']).optional(),
  }).parse(req.query);
  const { page, limit, offset } = pageParams(req);
  const params: unknown[] = [s.company_id];
  let where = 'f.company_id = $1';
  if (q.resolved) { params.push(q.resolved === 'true'); where += ` AND f.resolved = $${params.length}`; }
  if (q.severity) { params.push(q.severity); where += ` AND f.severity = $${params.length}`; }
  where += technicianScope(s, 'j.server_id', params);
  const out = await withCompany(s.company_id, async (db) => ({
    total: (await db.query(`SELECT COUNT(*)::int AS n FROM ${FAIL_FROM} WHERE ${where}`, params)).rows[0].n,
    rows: (await db.query(`SELECT ${FAIL_COLS} FROM ${FAIL_FROM} WHERE ${where} ORDER BY f.created_at DESC, f.id DESC LIMIT ${limit} OFFSET ${offset}`, params)).rows,
  }));
  res.json({ success: true, data: out.rows, pagination: pagination(page, limit, out.total) });
});

const resolveBody = z.object({
  resolution_type: z.enum(['retest_passed', 'backup_rerun', 'backup_fixed', 'false_positive', 'known_issue']),
  backup_usable: z.boolean(),
  notes: z.string().trim().min(1, 'Notes are required').max(5000),
});

testsRouter.post('/test-failures/:id/resolve', require_('failures:resolve'), async (req, res) => {
  const s = session(req);
  const id = idParam(req);
  const b = resolveBody.parse(req.body);
  const failure = await withCompany(s.company_id, async (db) => {
    const params: unknown[] = [id, s.company_id];
    const scope = technicianScope(s, 'j.server_id', params);
    const found = (await db.query(`SELECT f.resolved FROM ${FAIL_FROM} WHERE f.id = $1 AND f.company_id = $2${scope} FOR UPDATE OF f`, params)).rows[0];
    if (!found) throw notFound('Test failure', 'TEST_001');
    if (found.resolved) throw conflict('TEST_002', 'This failure has already been resolved');
    await db.query(
      `UPDATE backup_test_failures SET resolved = true, resolution_type = $2, resolution_notes = $3, backup_usable = $4,
              resolved_by = $5, resolved_at = NOW(), updated_at = NOW() WHERE id = $1`,
      [id, b.resolution_type, b.notes, b.backup_usable, s.user_id],
    );
    await audit(db, req, { action: 'failure_resolved', resource_type: 'test_failure', resource_id: id, details: b });
    return (await db.query(`SELECT ${FAIL_COLS} FROM ${FAIL_FROM} WHERE f.id = $1`, [id])).rows[0];
  });
  res.json({ success: true, data: failure });
});
