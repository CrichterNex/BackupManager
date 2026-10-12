import { Router } from 'express';
import { z } from 'zod';
import { require_, session } from '../auth.js';
import { withCompany } from '../db.js';
import { forbidden, notFound } from '../errors.js';
import { idParam, pageParams, pagination } from '../http.js';
import { technicianScope } from '../scope.js';

export const notificationsRouter = Router();

notificationsRouter.get('/', async (req, res) => {
  const s = session(req);
  const unread = req.query.unread === 'true';
  const { page, limit, offset } = pageParams(req);
  const out = await withCompany(s.company_id, async (db) => {
    const where = `user_id = $1 ${unread ? 'AND NOT is_read' : ''}`;
    return {
      total: (await db.query(`SELECT COUNT(*)::int AS n FROM notifications WHERE ${where}`, [s.user_id])).rows[0].n,
      unread: (await db.query('SELECT COUNT(*)::int AS n FROM notifications WHERE user_id = $1 AND NOT is_read', [s.user_id])).rows[0].n,
      rows: (await db.query(`SELECT * FROM notifications WHERE ${where} ORDER BY created_at DESC, id DESC LIMIT ${limit} OFFSET ${offset}`, [s.user_id])).rows,
    };
  });
  res.json({ success: true, data: out.rows, unread_count: out.unread, pagination: pagination(page, limit, out.total) });
});

notificationsRouter.post('/read-all', async (req, res) => {
  const s = session(req);
  const n = await withCompany(s.company_id, async (db) =>
    (await db.query('UPDATE notifications SET is_read = true, read_at = NOW() WHERE user_id = $1 AND NOT is_read', [s.user_id])).rowCount);
  res.json({ success: true, marked: n });
});

notificationsRouter.post('/:id/read', async (req, res) => {
  const s = session(req);
  const id = idParam(req);
  await withCompany(s.company_id, async (db) => {
    const { rowCount } = await db.query('UPDATE notifications SET is_read = true, read_at = COALESCE(read_at, NOW()) WHERE id = $1 AND user_id = $2', [id, s.user_id]);
    if (!rowCount) throw notFound('Notification');
  });
  res.json({ success: true });
});

export const auditRouter = Router();

auditRouter.get('/', require_('audit:read'), async (req, res) => {
  const s = session(req);
  const q = z.object({
    action: z.string().max(100).optional(),
    user_id: z.coerce.number().int().positive().optional(),
    date_from: z.coerce.date().optional(),
    date_to: z.coerce.date().optional(),
  }).parse(req.query);
  const { page, limit, offset } = pageParams(req);
  const params: unknown[] = [s.company_id];
  let where = 'a.company_id = $1';
  if (q.action) { params.push(q.action); where += ` AND a.action = $${params.length}`; }
  if (q.user_id) { params.push(q.user_id); where += ` AND a.user_id = $${params.length}`; }
  if (q.date_from) { params.push(q.date_from); where += ` AND a.created_at >= $${params.length}`; }
  if (q.date_to) { params.push(q.date_to); where += ` AND a.created_at <= $${params.length}`; }
  const out = await withCompany(s.company_id, async (db) => ({
    total: (await db.query(`SELECT COUNT(*)::int AS n FROM audit_trail a WHERE ${where}`, params)).rows[0].n,
    rows: (await db.query(
      `SELECT a.id, a.user_id, u.username, a.action, a.resource_type, a.resource_id, a.details, a.ip_address, a.success, a.created_at
         FROM audit_trail a LEFT JOIN users u ON u.id = a.user_id
        WHERE ${where} ORDER BY a.id DESC LIMIT ${limit} OFFSET ${offset}`, params)).rows,
  }));
  res.json({ success: true, data: out.rows, pagination: pagination(page, limit, out.total) });
});

export const dashboardRouter = Router();

/**
 * Health score (0-100): half backup success rate, half test pass rate, over the last 30 days.
 * If only one of the two has data, that one carries the whole score. No data returns null,
 * never a misleading 100.
 */
export function healthScore(backupRate: number | null, testRate: number | null): number | null {
  if (backupRate === null && testRate === null) return null;
  if (backupRate === null) return Math.round(testRate! * 100);
  if (testRate === null) return Math.round(backupRate * 100);
  return Math.round((backupRate * 0.5 + testRate * 0.5) * 100);
}

async function buildDashboard(req: Parameters<typeof session>[0]) {
  const s = session(req);
  return withCompany(s.company_id, async (db) => {
    const p1: unknown[] = [s.company_id];
    const sScope = technicianScope(s, 's.id', p1);
    const servers = (await db.query(
      `SELECT COUNT(*)::int AS total,
              COUNT(*) FILTER (WHERE last_backup_status = 'failed' OR last_test_status = 'failed')::int AS failing
         FROM servers s WHERE s.company_id = $1 AND s.active${sScope}`, p1)).rows[0];

    const p2: unknown[] = [s.company_id];
    const jScope = technicianScope(s, 'j.server_id', p2);
    const jobs = (await db.query(
      `SELECT COUNT(*) FILTER (WHERE status IN ('success','warning','failed') AND completed_time >= NOW() - INTERVAL '30 days')::int AS finished,
              COUNT(*) FILTER (WHERE status = 'success' AND completed_time >= NOW() - INTERVAL '30 days')::int AS success,
              COUNT(*) FILTER (WHERE status = 'warning' AND completed_time >= NOW() - INTERVAL '30 days')::int AS warning,
              COUNT(*) FILTER (WHERE status = 'failed' AND completed_time >= NOW() - INTERVAL '30 days')::int AS failed
         FROM backup_jobs j WHERE j.company_id = $1 AND j.deleted_at IS NULL${jScope}`, p2)).rows[0];

    const p3: unknown[] = [s.company_id];
    const tScope = technicianScope(s, 'j.server_id', p3);
    const tests = (await db.query(
      `SELECT COUNT(*) FILTER (WHERE t.test_status IN ('success','failed') AND t.completed_time >= NOW() - INTERVAL '24 hours')::int AS day_total,
              COUNT(*) FILTER (WHERE t.test_status = 'success' AND t.completed_time >= NOW() - INTERVAL '24 hours')::int AS day_passed,
              COUNT(*) FILTER (WHERE t.test_status IN ('success','failed') AND t.completed_time >= NOW() - INTERVAL '7 days')::int AS week_total,
              COUNT(*) FILTER (WHERE t.test_status = 'success' AND t.completed_time >= NOW() - INTERVAL '7 days')::int AS week_passed,
              COUNT(*) FILTER (WHERE t.test_status IN ('success','failed') AND t.completed_time >= NOW() - INTERVAL '30 days')::int AS month_total,
              COUNT(*) FILTER (WHERE t.test_status = 'success' AND t.completed_time >= NOW() - INTERVAL '30 days')::int AS month_passed
         FROM backup_test_executions t JOIN backup_jobs j ON j.id = t.backup_job_id
        WHERE t.company_id = $1${tScope}`, p3)).rows[0];

    const p4: unknown[] = [s.company_id];
    const fScope = technicianScope(s, 'j.server_id', p4);
    const open = (await db.query(
      `SELECT COUNT(*)::int AS n FROM backup_test_failures f JOIN backup_test_executions t ON t.id = f.test_execution_id
         JOIN backup_jobs j ON j.id = t.backup_job_id WHERE f.company_id = $1 AND NOT f.resolved${fScope}`, p4)).rows[0].n;

    const p5: unknown[] = [s.company_id];
    const rScope = technicianScope(s, 'j.server_id', p5);
    const recent = (await db.query(
      `SELECT j.id, s.hostname, j.backup_method, j.status, j.size_gb, j.completed_time
         FROM backup_jobs j JOIN servers s ON s.id = j.server_id
        WHERE j.company_id = $1 AND j.deleted_at IS NULL AND j.completed_time IS NOT NULL${rScope}
        ORDER BY j.completed_time DESC LIMIT 10`, p5)).rows;

    const rate = (n: number, d: number) => (d === 0 ? null : n / d);
    return {
      company: { id: s.company_id, name: s.company_name, slug: s.company_slug },
      servers,
      backups_30d: jobs,
      tests: {
        last_24h: { total: tests.day_total, passed: tests.day_passed },
        last_7d: { total: tests.week_total, passed: tests.week_passed },
        last_30d: { total: tests.month_total, passed: tests.month_passed },
      },
      unresolved_failures: open,
      health_score: healthScore(rate(jobs.success + jobs.warning, jobs.finished), rate(tests.month_passed, tests.month_total)),
      recent_backups: recent,
    };
  });
}

dashboardRouter.get('/dashboard', require_('dashboard:read'), async (req, res) => {
  res.json({ success: true, data: await buildDashboard(req) });
});

// Spec path: refuses any company other than the caller's own.
dashboardRouter.get('/companies/:id/dashboard', require_('dashboard:read'), async (req, res) => {
  if (idParam(req) !== session(req).company_id) throw forbidden('Access denied');
  res.json({ success: true, data: await buildDashboard(req) });
});
