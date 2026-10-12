import { config } from '../config.js';
import type { Db } from '../db.js';
import type { ReportType } from '../constants.js';

/**
 * Report figures come only from what BMS records. Anything the blueprint's mockups show that BMS cannot
 * measure (uptime, restore-time actuals, encryption, service credits, framework attestations) is listed
 * under `not_measured` instead of being filled in, because a certificate with invented numbers is worse
 * than one with gaps.
 */

export interface Period { from: Date; to: Date }

export interface SlaTargets { backup_success_pct: number; test_coverage_pct: number; test_success_pct: number }
export const DEFAULT_TARGETS: SlaTargets = { backup_success_pct: 99, test_coverage_pct: 95, test_success_pct: 95 };

export interface DrParams { max_backup_age_hours: number; max_test_age_days: number }
export const DEFAULT_DR: DrParams = { max_backup_age_hours: 24, max_test_age_days: 30 };

export const pct = (n: number, d: number): number | null => (d === 0 ? null : Math.round((n / d) * 1000) / 10);
const num = (v: unknown): number | null => (v == null ? null : Number(v));

export type Level = 'compliant' | 'compliant_with_notes' | 'non_compliant' | 'insufficient_data';

async function backupFigures(db: Db, companyId: number, p: Period) {
  const r = (await db.query(
    `SELECT COUNT(*) FILTER (WHERE status IN ('success','warning','failed'))::int AS finished,
            COUNT(*) FILTER (WHERE status = 'success')::int AS success,
            COUNT(*) FILTER (WHERE status = 'warning')::int AS warning,
            COUNT(*) FILTER (WHERE status = 'failed')::int AS failed,
            AVG(EXTRACT(EPOCH FROM (completed_time - started_time))) FILTER (WHERE started_time IS NOT NULL AND status IN ('success','warning')) AS avg_seconds,
            percentile_cont(0.95) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (completed_time - started_time)))
              FILTER (WHERE started_time IS NOT NULL AND status IN ('success','warning')) AS p95_seconds,
            AVG(size_gb) FILTER (WHERE status IN ('success','warning')) AS avg_size_gb
       FROM backup_jobs WHERE company_id = $1 AND deleted_at IS NULL AND completed_time >= $2 AND completed_time < $3`,
    [companyId, p.from, p.to])).rows[0];
  return {
    finished: r.finished, success: r.success, warning: r.warning, failed: r.failed,
    success_rate_pct: pct(r.success, r.finished),
    avg_duration_seconds: num(r.avg_seconds) === null ? null : Math.round(num(r.avg_seconds)!),
    p95_duration_seconds: num(r.p95_seconds) === null ? null : Math.round(num(r.p95_seconds)!),
    avg_size_gb: num(r.avg_size_gb) === null ? null : Math.round(num(r.avg_size_gb)! * 100) / 100,
  };
}

async function testFigures(db: Db, companyId: number, p: Period) {
  const base = `FROM backup_test_executions t JOIN backup_jobs j ON j.id = t.backup_job_id
                WHERE t.company_id = $1 AND t.completed_time >= $2 AND t.completed_time < $3`;
  const total = (await db.query(
    `SELECT COUNT(*) FILTER (WHERE t.test_status IN ('success','failed'))::int AS run,
            COUNT(*) FILTER (WHERE t.test_status = 'success')::int AS passed,
            COUNT(*) FILTER (WHERE t.test_status = 'failed')::int AS failed,
            COUNT(*) FILTER (WHERE t.test_status = 'skipped')::int AS skipped,
            COUNT(*) FILTER (WHERE t.hash_match IS NOT NULL)::int AS hash_checked,
            COUNT(*) FILTER (WHERE t.hash_match = false)::int AS hash_mismatches
       ${base}`, [companyId, p.from, p.to])).rows[0];
  const byType = (await db.query(
    `SELECT t.test_type, COUNT(*)::int AS run, COUNT(*) FILTER (WHERE t.test_status = 'success')::int AS passed
       ${base} AND t.test_status IN ('success','failed') GROUP BY t.test_type ORDER BY t.test_type`, [companyId, p.from, p.to])).rows;
  const servers = (await db.query(
    `SELECT (SELECT COUNT(*)::int FROM servers WHERE company_id = $1 AND active) AS active,
            COUNT(DISTINCT j.server_id)::int AS tested
       ${base} AND t.test_status IN ('success','failed')`, [companyId, p.from, p.to])).rows[0];
  return {
    run: total.run, passed: total.passed, failed: total.failed, skipped: total.skipped,
    pass_rate_pct: pct(total.passed, total.run),
    hash_checked: total.hash_checked, hash_mismatches: total.hash_mismatches,
    by_type: byType,
    servers_active: servers.active, servers_tested: servers.tested,
    coverage_pct: pct(servers.tested, servers.active),
  };
}

async function failureFigures(db: Db, companyId: number, p: Period) {
  const raised = (await db.query(
    `SELECT f.severity, COUNT(*)::int AS raised, COUNT(*) FILTER (WHERE f.resolved)::int AS resolved,
            AVG(EXTRACT(EPOCH FROM (f.resolved_at - f.created_at))) FILTER (WHERE f.resolved) / 3600 AS mttr_hours
       FROM backup_test_failures f WHERE f.company_id = $1 AND f.created_at >= $2 AND f.created_at < $3
      GROUP BY f.severity`, [companyId, p.from, p.to])).rows;
  const order = ['critical', 'high', 'medium', 'low'];
  const bySeverity = order.map((s) => {
    const r = raised.find((x) => x.severity === s);
    return { severity: s, raised: r?.raised ?? 0, resolved: r?.resolved ?? 0, mttr_hours: r?.mttr_hours == null ? null : Math.round(Number(r.mttr_hours) * 10) / 10 };
  });
  const resolution = (await db.query(
    `SELECT resolution_type, COUNT(*)::int AS n FROM backup_test_failures
      WHERE company_id = $1 AND created_at >= $2 AND created_at < $3 AND resolved GROUP BY resolution_type ORDER BY resolution_type`,
    [companyId, p.from, p.to])).rows;
  const open = (await db.query(
    `SELECT severity, COUNT(*)::int AS n FROM backup_test_failures WHERE company_id = $1 AND NOT resolved GROUP BY severity`, [companyId])).rows;
  const openBy = (s: string) => open.find((x) => x.severity === s)?.n ?? 0;
  const raisedTotal = bySeverity.reduce((a, b) => a + b.raised, 0);
  const resolvedTotal = bySeverity.reduce((a, b) => a + b.resolved, 0);
  const mttrRows = raised.filter((r) => r.mttr_hours != null);
  const mttrWeighted = mttrRows.reduce((a, r) => a + Number(r.mttr_hours) * r.resolved, 0);
  const mttrCount = mttrRows.reduce((a, r) => a + r.resolved, 0);
  return {
    raised: raisedTotal, resolved: resolvedTotal,
    mttr_hours: mttrCount ? Math.round((mttrWeighted / mttrCount) * 10) / 10 : null,
    by_severity: bySeverity,
    resolution_types: resolution,
    open_now: { critical: openBy('critical'), high: openBy('high'), medium: openBy('medium'), low: openBy('low') },
  };
}

const companyInfo = async (db: Db, companyId: number) =>
  (await db.query('SELECT name, slug, contact_name, contact_email FROM companies WHERE id = $1', [companyId])).rows[0];

const NOT_MEASURED_COMMON = [
  'Backup encryption (BMS does not see how a backup tool stores data)',
  'Storage capacity and utilisation',
  'Restore times (the test engine records a test duration, not a full recovery time)',
];

export function certify(backups: { finished: number; success_rate_pct: number | null }, tests: { run: number; coverage_pct: number | null; pass_rate_pct: number | null },
  openCriticalOrHigh: number, openAny: number, t: SlaTargets): { level: Level; reasons: string[] } {
  if (backups.finished === 0 || tests.run === 0) {
    return { level: 'insufficient_data', reasons: [backups.finished === 0 ? 'No backups finished in the period.' : 'No tests ran in the period.'] };
  }
  const misses: string[] = [];
  if ((backups.success_rate_pct ?? 0) < t.backup_success_pct) misses.push(`Backup success ${backups.success_rate_pct}% is below the ${t.backup_success_pct}% target.`);
  if ((tests.coverage_pct ?? 0) < t.test_coverage_pct) misses.push(`Test coverage ${tests.coverage_pct}% is below the ${t.test_coverage_pct}% target.`);
  if ((tests.pass_rate_pct ?? 0) < t.test_success_pct) misses.push(`Test pass rate ${tests.pass_rate_pct}% is below the ${t.test_success_pct}% target.`);
  if (openCriticalOrHigh > 0) misses.push(`${openCriticalOrHigh} critical or high test failure(s) are unresolved.`);
  if (misses.length) return { level: 'non_compliant', reasons: misses };
  if (openAny > 0) return { level: 'compliant_with_notes', reasons: [`${openAny} lower-severity test failure(s) are unresolved.`] };
  return { level: 'compliant', reasons: ['All targets met and no unresolved test failures.'] };
}

export async function complianceReport(db: Db, companyId: number, p: Period, targets = DEFAULT_TARGETS) {
  const [company, backups, tests, failures] = await Promise.all([
    companyInfo(db, companyId), backupFigures(db, companyId, p), testFigures(db, companyId, p), failureFigures(db, companyId, p),
  ]);
  const servers = (await db.query(
    `SELECT COUNT(*)::int AS total, COUNT(*) FILTER (WHERE backup_location_path IS NOT NULL)::int AS with_location,
            COUNT(*) FILTER (WHERE retention_policy_days IS NOT NULL)::int AS with_retention
       FROM servers WHERE company_id = $1 AND active`, [companyId])).rows[0];
  const audit = (await db.query('SELECT COUNT(*)::int AS n FROM audit_trail WHERE company_id = $1 AND created_at >= $2 AND created_at < $3', [companyId, p.from, p.to])).rows[0].n;
  const { open_now: o } = failures;
  const cert = certify(backups, tests, o.critical + o.high, o.medium + o.low, targets);
  return {
    company, servers, backups, tests, failures, targets,
    audit_entries: audit,
    certification: cert,
    verification_method: 'SHA-256 hash comparison of the backup copy and the restored copy',
    not_measured: NOT_MEASURED_COMMON,
  };
}

export async function slaReport(db: Db, companyId: number, p: Period, targets = DEFAULT_TARGETS) {
  const [company, backups, tests, failures] = await Promise.all([
    companyInfo(db, companyId), backupFigures(db, companyId, p), testFigures(db, companyId, p), failureFigures(db, companyId, p),
  ]);
  const status = (actual: number | null, target: number) => (actual === null ? 'no_data' : actual >= target ? 'met' : 'missed');
  const rows = [
    { metric: 'Backup execution success', target_pct: targets.backup_success_pct, actual_pct: backups.success_rate_pct, status: status(backups.success_rate_pct, targets.backup_success_pct) },
    { metric: 'Test coverage (servers tested in period)', target_pct: targets.test_coverage_pct, actual_pct: tests.coverage_pct, status: status(tests.coverage_pct, targets.test_coverage_pct) },
    { metric: 'Test pass rate', target_pct: targets.test_success_pct, actual_pct: tests.pass_rate_pct, status: status(tests.pass_rate_pct, targets.test_success_pct) },
  ];
  const measured = rows.filter((r) => r.status !== 'no_data');
  return {
    company, targets, metrics: rows,
    summary: { measurable: measured.length, met: measured.filter((r) => r.status === 'met').length, missed: measured.filter((r) => r.status === 'missed').length },
    backups: { finished: backups.finished, failed: backups.failed, avg_duration_seconds: backups.avg_duration_seconds, p95_duration_seconds: backups.p95_duration_seconds },
    tests: { run: tests.run, passed: tests.passed, failed: tests.failed, skipped: tests.skipped, servers_tested: tests.servers_tested, servers_active: tests.servers_active },
    incidents: failures,
    not_measured: ['Scheduling uptime and availability', 'RTO and RPO actuals', 'Service credits and penalties', 'Backup window adherence', ...NOT_MEASURED_COMMON.slice(2)],
  };
}

export async function securityReport(db: Db, companyId: number, p: Period) {
  const company = await companyInfo(db, companyId);
  const users = (await db.query(
    `SELECT role, auth_method, COUNT(*) FILTER (WHERE active)::int AS active, COUNT(*) FILTER (WHERE NOT active)::int AS disabled
       FROM users WHERE company_id = $1 GROUP BY role, auth_method ORDER BY role, auth_method`, [companyId])).rows;
  const dormant = (await db.query(
    `SELECT COUNT(*)::int AS n FROM users WHERE company_id = $1 AND active AND (last_login IS NULL OR last_login < NOW() - INTERVAL '90 days')`, [companyId])).rows[0].n;
  const actions = (await db.query(
    `SELECT action, COUNT(*)::int AS total, COUNT(*) FILTER (WHERE NOT success)::int AS failed
       FROM audit_trail WHERE company_id = $1 AND created_at >= $2 AND created_at < $3 GROUP BY action ORDER BY total DESC, action`,
    [companyId, p.from, p.to])).rows;
  const count = (a: string) => actions.find((x) => x.action === a)?.total ?? 0;
  const admins = (await db.query(`SELECT COUNT(*)::int AS n FROM users WHERE company_id = $1 AND active AND role = 'company_admin'`, [companyId])).rows[0].n;
  return {
    company,
    users: { by_role: users, active_admins: admins, active_without_recent_login: dormant },
    sign_ins: { successful: count('login'), failed_attributed_to_a_user: count('login_failed') },
    password_changes: count('password_changed'),
    audit: { entries: actions.reduce((a, b) => a + b.total, 0), by_action: actions },
    // These describe how BMS itself is built, and are checked by the test suite.
    controls: [
      { control: 'Tenant isolation', detail: 'Every query is filtered by company, and Postgres row-level security blocks cross-company reads and writes.' },
      { control: 'Audit trail', detail: 'Write-once. A database trigger rejects updates and deletes of audit entries.' },
      { control: 'Passwords', detail: `bcrypt-hashed, minimum 12 characters.` },
      { control: 'Sessions', detail: `${config.sessionHours}-hour HttpOnly cookie, with login rate limiting.` },
      { control: 'Access control', detail: 'Three roles. Technicians only see servers assigned to them.' },
    ],
    not_measured: ['Compliance with named frameworks (ISO 27001, SOC 2, HIPAA, GDPR, PCI-DSS). BMS records evidence, it does not assess frameworks.', 'Backup encryption', 'Network security of backup transfers', 'Third-party and vendor risk'],
  };
}

export async function drReport(db: Db, companyId: number, params = DEFAULT_DR) {
  const company = await companyInfo(db, companyId);
  const rows = (await db.query(
    `SELECT s.id, s.hostname, s.os_type, s.criticality, s.backup_methods, s.last_backup_time, s.last_backup_status, s.last_test_time, s.last_test_status,
            EXTRACT(EPOCH FROM (NOW() - s.last_backup_time)) / 3600 AS backup_age_hours,
            EXTRACT(EPOCH FROM (NOW() - s.last_test_time)) / 86400 AS test_age_days,
            (SELECT COUNT(*)::int FROM backup_test_failures f JOIN backup_test_executions t ON t.id = f.test_execution_id JOIN backup_jobs j ON j.id = t.backup_job_id
              WHERE j.server_id = s.id AND NOT f.resolved AND f.severity IN ('critical','high')) AS open_serious
       FROM servers s WHERE s.company_id = $1 AND s.active ORDER BY s.hostname`, [companyId])).rows;
  const servers = rows.map((r) => {
    const reasons: string[] = [];
    if (r.last_backup_time == null) reasons.push('No backup recorded');
    else if (r.last_backup_status === 'failed') reasons.push('Latest backup failed');
    else if (Number(r.backup_age_hours) > params.max_backup_age_hours) reasons.push(`Latest backup is older than ${params.max_backup_age_hours} hours`);
    if (r.last_test_time == null) reasons.push('Never tested');
    else if (r.last_test_status === 'failed') reasons.push('Latest test failed');
    else if (Number(r.test_age_days) > params.max_test_age_days) reasons.push(`Latest test is older than ${params.max_test_age_days} days`);
    if (r.open_serious > 0) reasons.push(`${r.open_serious} unresolved critical or high failure(s)`);
    return {
      hostname: r.hostname, os_type: r.os_type, criticality: r.criticality, backup_methods: r.backup_methods,
      last_backup_time: r.last_backup_time, last_backup_status: r.last_backup_status,
      last_test_time: r.last_test_time, last_test_status: r.last_test_status,
      backup_age_hours: r.backup_age_hours == null ? null : Math.round(Number(r.backup_age_hours) * 10) / 10,
      test_age_days: r.test_age_days == null ? null : Math.round(Number(r.test_age_days) * 10) / 10,
      ready: reasons.length === 0, reasons,
    };
  });
  const full = (await db.query(
    `SELECT MAX(completed_time) AS last FROM backup_test_executions WHERE company_id = $1 AND test_type = 'full_recovery' AND test_status = 'success'`, [companyId])).rows[0].last;
  const ready = servers.filter((s) => s.ready).length;
  return {
    company, params, servers,
    summary: {
      total: servers.length, ready, not_ready: servers.length - ready,
      status: servers.length === 0 ? 'no_servers' : ready === servers.length ? 'ready' : 'not_ready',
    },
    last_full_recovery_test: full,
    not_measured: ['Recovery documentation, procedures and staff training', 'Validated restore times and RTO/RPO', 'Immutability or air-gapping of backup storage', ...(full ? [] : ['No full recovery test has been recorded (the engine does not automate these yet)'])],
  };
}

export async function buildReport(db: Db, companyId: number, type: ReportType, p: Period, opts: { targets?: SlaTargets; dr?: DrParams } = {}) {
  const generated_at = new Date().toISOString();
  const head = { type, generated_at, period: { from: p.from.toISOString(), to: p.to.toISOString() } };
  switch (type) {
    case 'compliance': return { ...head, ...(await complianceReport(db, companyId, p, opts.targets)) };
    case 'sla': return { ...head, ...(await slaReport(db, companyId, p, opts.targets)) };
    case 'security': return { ...head, ...(await securityReport(db, companyId, p)) };
    case 'dr_readiness': return { ...head, ...(await drReport(db, companyId, opts.dr)) };
  }
}

export type ReportSnapshot = Awaited<ReturnType<typeof buildReport>>;
