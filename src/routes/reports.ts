import { Router } from 'express';
import { z } from 'zod';
import { audit } from '../audit.js';
import { require_, session } from '../auth.js';
import { REPORT_TYPES } from '../constants.js';
import { withCompany } from '../db.js';
import { badRequest, notFound } from '../errors.js';
import { idParam, pageParams, pagination } from '../http.js';
import { DEFAULT_DR, DEFAULT_TARGETS } from '../reports/data.js';
import { renderReport } from '../reports/render.js';
import { generateReport } from '../reports/service.js';

export const reportsRouter = Router();

const COLS = 'id, report_type, period_start, period_end, generated_by, created_at';
const DAY = 86_400_000;

reportsRouter.get('/', require_('reports:read'), async (req, res) => {
  const s = session(req);
  const { type } = z.object({ type: z.enum(REPORT_TYPES).optional() }).parse(req.query);
  const { page, limit, offset } = pageParams(req);
  const params: unknown[] = [s.company_id];
  let where = 'company_id = $1';
  if (type) { params.push(type); where += ` AND report_type = $${params.length}`; }
  const out = await withCompany(s.company_id, async (db) => ({
    total: (await db.query(`SELECT COUNT(*)::int AS n FROM reports WHERE ${where}`, params)).rows[0].n,
    rows: (await db.query(`SELECT ${COLS} FROM reports WHERE ${where} ORDER BY created_at DESC, id DESC LIMIT ${limit} OFFSET ${offset}`, params)).rows,
  }));
  res.json({ success: true, data: out.rows, pagination: pagination(page, limit, out.total) });
});

const pctField = z.number().min(0).max(100);
const generateBody = z.object({
  report_type: z.enum(REPORT_TYPES),
  period_start: z.coerce.date().optional(),
  period_end: z.coerce.date().optional(),
  targets: z.object({ backup_success_pct: pctField, test_coverage_pct: pctField, test_success_pct: pctField }).partial().optional(),
  dr: z.object({ max_backup_age_hours: z.number().min(1).max(8760), max_test_age_days: z.number().min(1).max(3650) }).partial().optional(),
});

reportsRouter.post('/', require_('reports:generate'), async (req, res) => {
  const s = session(req);
  const b = generateBody.parse(req.body);
  const now = new Date();
  let period = { from: now, to: now };
  if (b.report_type !== 'dr_readiness') {
    if (!b.period_start || !b.period_end) throw badRequest('period_start and period_end are required');
    if (b.period_end <= b.period_start) throw badRequest('period_end must be after period_start');
    if (b.period_end.getTime() - b.period_start.getTime() > 366 * DAY) throw badRequest('A report period cannot be longer than 366 days');
    if (b.period_end.getTime() > now.getTime() + DAY) throw badRequest('period_end cannot be in the future');
    period = { from: b.period_start, to: b.period_end };
  }
  const report = await withCompany(s.company_id, async (db) => {
    const r = await generateReport(db, s.company_id, b.report_type, period, s.user_id, {
      targets: { ...DEFAULT_TARGETS, ...b.targets }, dr: { ...DEFAULT_DR, ...b.dr },
    });
    await audit(db, req, { action: 'report_generated', resource_type: 'report', resource_id: r.id, details: { report_type: b.report_type, period_start: period.from, period_end: period.to } });
    return r;
  });
  res.status(201).json({ success: true, data: report });
});

const load = (companyId: number, id: number) =>
  withCompany(companyId, async (db) => {
    const row = (await db.query(`SELECT ${COLS}, data FROM reports WHERE id = $1 AND company_id = $2`, [id, companyId])).rows[0];
    if (!row) throw notFound('Report', 'REPORT_001');
    return row;
  });

reportsRouter.get('/:id', require_('reports:read'), async (req, res) => {
  res.json({ success: true, data: await load(session(req).company_id, idParam(req)) });
});

// Print-ready page. Use the browser's Print > Save as PDF to produce a PDF.
reportsRouter.get('/:id/view', require_('reports:read'), async (req, res) => {
  const row = await load(session(req).company_id, idParam(req));
  res.type('html').send(renderReport(row.data, row));
});
