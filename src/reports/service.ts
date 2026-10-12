import type { ReportType } from '../constants.js';
import { withCompany, withTransaction, type Db } from '../db.js';
import { buildReport, type DrParams, type Period, type SlaTargets } from './data.js';

export interface StoredReport { id: number; report_type: ReportType; period_start: string; period_end: string; generated_by: number | null; created_at: string }

/** Build a report from current data and archive the snapshot. Runs inside the caller's company transaction. */
export async function generateReport(
  db: Db, companyId: number, type: ReportType, period: Period, generatedBy: number | null,
  opts: { targets?: SlaTargets; dr?: DrParams } = {},
): Promise<StoredReport> {
  const data = await buildReport(db, companyId, type, period, opts);
  const { rows } = await db.query(
    `INSERT INTO reports (company_id, report_type, period_start, period_end, data, generated_by)
     VALUES ($1,$2,$3,$4,$5,$6) RETURNING id, report_type, period_start, period_end, generated_by, created_at`,
    [companyId, type, period.from, period.to, JSON.stringify(data), generatedBy],
  );
  return rows[0];
}

/** Calendar month before `now`, in UTC. */
export function previousMonth(now: Date): Period {
  return { from: new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth() - 1, 1)), to: new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), 1)) };
}

/** Calendar quarter before `now`, in UTC. */
export function previousQuarter(now: Date): Period {
  const q = Math.floor(now.getUTCMonth() / 3);
  return { from: new Date(Date.UTC(now.getUTCFullYear(), (q - 1) * 3, 1)), to: new Date(Date.UTC(now.getUTCFullYear(), q * 3, 1)) };
}

/**
 * Archive the compliance report for last month and the SLA report for last quarter, once each, for every
 * active company that existed during that period. Returns the number of reports created.
 */
export async function generateScheduledReports(now = new Date()): Promise<number> {
  const companies = await withTransaction(async (db) => (await db.query('SELECT id, created_at FROM companies WHERE is_active')).rows as { id: number; created_at: Date }[]);
  const plan: [ReportType, Period][] = [['compliance', previousMonth(now)], ['sla', previousQuarter(now)]];
  let made = 0;
  for (const c of companies) {
    for (const [type, period] of plan) {
      if (c.created_at >= period.to) continue;
      made += await withCompany(c.id, async (db) => {
        const exists = await db.query('SELECT 1 FROM reports WHERE company_id = $1 AND report_type = $2 AND period_start = $3 AND period_end = $4', [c.id, type, period.from, period.to]);
        if (exists.rowCount) return 0;
        await generateReport(db, c.id, type, period, null);
        return 1;
      });
    }
  }
  return made;
}
