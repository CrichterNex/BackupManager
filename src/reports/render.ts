/** Server-rendered, print-ready HTML for a stored report snapshot. Every value is escaped. */

type Snap = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

export const esc = (v: unknown): string =>
  String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);

const TITLES: Record<string, string> = {
  compliance: 'Backup Compliance Report',
  sla: 'SLA Performance Report',
  security: 'Security and Access Report',
  dr_readiness: 'Disaster Recovery Readiness Report',
};
const LEVELS: Record<string, [string, string]> = {
  compliant: ['Compliant', 'good'],
  compliant_with_notes: ['Compliant with notes', 'warn'],
  non_compliant: ['Non-compliant', 'bad'],
  insufficient_data: ['Insufficient data', 'muted'],
};

const label = (s: unknown) => esc(String(s ?? '').replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()));
const date = (v: unknown) => (v ? esc(new Date(String(v)).toISOString().slice(0, 16).replace('T', ' ') + ' UTC') : 'Never');
const day = (v: unknown) => esc(new Date(String(v)).toISOString().slice(0, 10));
const val = (v: unknown, suffix = '') => (v == null ? '<span class="muted">No data</span>' : `${esc(v)}${suffix}`);
const dur = (s: number | null) => (s == null ? '<span class="muted">No data</span>' : s >= 3600 ? `${Math.floor(s / 3600)} h ${Math.round((s % 3600) / 60)} min` : `${Math.round(s / 60)} min`);

const table = (heads: string[], rows: string[][], empty = 'Nothing to show for this period.') =>
  rows.length
    ? `<table><thead><tr>${heads.map((h) => `<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows.map((r) => `<tr>${r.map((c) => `<td>${c}</td>`).join('')}</tr>`).join('')}</tbody></table>`
    : `<p class="muted">${esc(empty)}</p>`;
const kv = (rows: [string, string][]) => `<dl>${rows.map(([k, v]) => `<div><dt>${esc(k)}</dt><dd>${v}</dd></div>`).join('')}</dl>`;
const section = (title: string, body: string) => `<section><h2>${esc(title)}</h2>${body}</section>`;
const badge = (text: string, tone: string) => `<span class="badge ${tone}">${esc(text)}</span>`;

const notMeasured = (items: string[]) =>
  section('Not measured by BMS', `<p class="muted">These items appear in typical compliance reports but BMS has no data for them, so they are not stated here.</p><ul>${items.map((i) => `<li>${esc(i)}</li>`).join('')}</ul>`);

const signature = (company: string) =>
  section('Sign-off', `<p class="small">The figures above are calculated from records held in BMS for ${esc(company)}. This report is not an independent audit.</p>
  <div class="sign"><div>Prepared by<br><br>__________________________</div><div>Name and title<br><br>__________________________</div><div>Date<br><br>______________</div></div>`);

function complianceBody(s: Snap): string {
  const [levelText, tone] = LEVELS[s.certification.level]!;
  const t = s.tests, b = s.backups, f = s.failures;
  return [
    section('Summary', `<p class="status ${tone}">${esc(levelText)}</p><ul>${s.certification.reasons.map((r: string) => `<li>${esc(r)}</li>`).join('')}</ul>` +
      kv([['Active servers', esc(s.servers.total)], ['Backups finished', esc(b.finished)], ['Tests run', esc(t.run)], ['Unresolved failures', esc(Object.values(f.open_now).reduce((a: number, c) => a + Number(c), 0))]])),
    section('Backup health', kv([
      ['Success rate', val(b.success_rate_pct, '%') + ` (${esc(b.success)} of ${esc(b.finished)})`],
      ['Succeeded with warnings', esc(b.warning)], ['Failed', esc(b.failed)],
      ['Average duration', dur(b.avg_duration_seconds)], ['Average size', val(b.avg_size_gb, ' GB')],
    ])),
    section('Test and verification evidence', kv([
      ['Test coverage', val(t.coverage_pct, '%') + ` (${esc(t.servers_tested)} of ${esc(t.servers_active)} servers tested)`],
      ['Tests run', esc(t.run)], ['Passed', esc(t.passed)], ['Failed', esc(t.failed)], ['Skipped (not run)', esc(t.skipped)],
      ['Pass rate', val(t.pass_rate_pct, '%')],
      ['Hash-verified', `${esc(t.hash_checked)} tests, ${esc(t.hash_mismatches)} mismatch(es)`],
      ['Verification method', esc(s.verification_method)],
    ]) + table(['Test type', 'Run', 'Passed'], t.by_type.map((x: Snap) => [label(x.test_type), esc(x.run), esc(x.passed)]), 'No tests completed in this period.')),
    section('Failures and resolution', kv([['Raised in period', esc(f.raised)], ['Resolved', esc(f.resolved)], ['Mean time to resolve', val(f.mttr_hours, ' hours')]]) +
      table(['Resolution', 'Count'], f.resolution_types.map((x: Snap) => [label(x.resolution_type), esc(x.n)]), 'No failures were resolved in this period.')),
    section('Retention and records', kv([
      ['Servers with a retention policy', `${esc(s.servers.with_retention)} of ${esc(s.servers.total)}`],
      ['Servers with a backup location recorded', `${esc(s.servers.with_location)} of ${esc(s.servers.total)}`],
      ['Audit trail entries in period', esc(s.audit_entries)],
    ])),
    notMeasured(s.not_measured),
  ].join('');
}

function slaBody(s: Snap): string {
  const tone = (st: string) => (st === 'met' ? 'good' : st === 'missed' ? 'bad' : 'muted');
  const inc = s.incidents;
  return [
    section('Service levels against targets', table(['Metric', 'Target', 'Actual', 'Status'],
      s.metrics.map((m: Snap) => [esc(m.metric), `${esc(m.target_pct)}%`, val(m.actual_pct, '%'), badge(({ met: 'Met', missed: 'Missed', no_data: 'No data' } as Record<string, string>)[m.status]!, tone(m.status))])) +
      `<p>${esc(s.summary.met)} of ${esc(s.summary.measurable)} measurable targets met.</p>`),
    section('Backup performance', kv([['Backups finished', esc(s.backups.finished)], ['Failed', esc(s.backups.failed)], ['Average duration', dur(s.backups.avg_duration_seconds)], ['95th percentile duration', dur(s.backups.p95_duration_seconds)]])),
    section('Testing', kv([['Tests run', esc(s.tests.run)], ['Passed', esc(s.tests.passed)], ['Failed', esc(s.tests.failed)], ['Skipped', esc(s.tests.skipped)], ['Servers tested', `${esc(s.tests.servers_tested)} of ${esc(s.tests.servers_active)}`]])),
    section('Incidents', table(['Severity', 'Raised', 'Resolved', 'Mean time to resolve'],
      inc.by_severity.map((x: Snap) => [label(x.severity), esc(x.raised), esc(x.resolved), val(x.mttr_hours, ' hours')])) +
      `<p>Unresolved now: ${esc(inc.open_now.critical)} critical, ${esc(inc.open_now.high)} high, ${esc(inc.open_now.medium)} medium, ${esc(inc.open_now.low)} low.</p>`),
    notMeasured(s.not_measured),
  ].join('');
}

function securityBody(s: Snap): string {
  return [
    section('Users and access', table(['Role', 'Sign-in method', 'Active', 'Disabled'], s.users.by_role.map((u: Snap) => [label(u.role.replace('company_', '')), esc(u.auth_method), esc(u.active), esc(u.disabled)]), 'No users.') +
      kv([['Active admins', esc(s.users.active_admins)], ['Active users with no login in 90 days', esc(s.users.active_without_recent_login)]])),
    section('Activity in period', kv([['Successful sign-ins', esc(s.sign_ins.successful)], ['Failed sign-ins (known user)', esc(s.sign_ins.failed_attributed_to_a_user)], ['Password changes', esc(s.password_changes)], ['Audit entries', esc(s.audit.entries)]]) +
      table(['Action', 'Count', 'Failed'], s.audit.by_action.map((a: Snap) => [label(a.action), esc(a.total), esc(a.failed)]), 'No audited activity in this period.')),
    section('Built-in controls', table(['Control', 'Detail'], s.controls.map((c: Snap) => [esc(c.control), esc(c.detail)]))),
    notMeasured(s.not_measured),
  ].join('');
}

function drBody(s: Snap): string {
  const [text, tone] = s.summary.status === 'ready' ? ['Ready', 'good'] : s.summary.status === 'no_servers' ? ['No servers', 'muted'] : ['Not ready', 'bad'];
  return [
    section('Summary', `<p class="status ${tone}">${esc(text)}</p>` + kv([
      ['Servers ready', `${esc(s.summary.ready)} of ${esc(s.summary.total)}`],
      ['Rule', `Latest backup succeeded within ${esc(s.params.max_backup_age_hours)} hours, latest test passed within ${esc(s.params.max_test_age_days)} days, and no unresolved critical or high failures.`],
      ['Last full recovery test', s.last_full_recovery_test ? date(s.last_full_recovery_test) : '<span class="muted">None recorded</span>'],
    ])),
    section('Server readiness', table(['Server', 'OS', 'Last backup', 'Last test', 'Ready', 'Why not'],
      s.servers.map((v: Snap) => [esc(v.hostname), esc(v.os_type), `${date(v.last_backup_time)}${v.last_backup_status ? ` (${esc(v.last_backup_status)})` : ''}`,
        `${date(v.last_test_time)}${v.last_test_status ? ` (${esc(v.last_test_status)})` : ''}`, badge(v.ready ? 'Yes' : 'No', v.ready ? 'good' : 'bad'), esc(v.reasons.join('; '))]), 'No active servers.')),
    notMeasured(s.not_measured),
  ].join('');
}

const CSS = `
:root{--ink:#1f2933;--muted:#6b7785;--line:#d9dee4;--accent:#185fa5;--good:#0f6e56;--warn:#9a6700;--bad:#b42318}
*{box-sizing:border-box}body{margin:0;background:#eef1f4;color:var(--ink);font:14px/1.55 system-ui,'Segoe UI',Arial,sans-serif}
.page{max-width:900px;margin:24px auto;background:#fff;padding:48px;box-shadow:0 1px 8px rgba(0,0,0,.1)}
header{border-bottom:3px solid var(--accent);padding-bottom:16px;margin-bottom:24px}
h1{margin:0 0 4px;font-size:26px;color:var(--accent)}h2{font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:var(--accent);border-bottom:1px solid var(--line);padding-bottom:6px;margin:28px 0 12px}
.sub{color:var(--muted)}.muted{color:var(--muted)}.small{font-size:12px}
table{width:100%;border-collapse:collapse;margin:8px 0}th{text-align:left;background:#f4f6f8;border-bottom:2px solid var(--line)}th,td{padding:7px 9px;border-bottom:1px solid var(--line);vertical-align:top}
dl{margin:8px 0}dl div{display:flex;gap:12px;padding:4px 0;border-bottom:1px dotted var(--line)}dt{width:260px;color:var(--muted);flex:none}dd{margin:0}
.status{display:inline-block;font-size:18px;font-weight:700;padding:6px 16px;border-radius:999px;border:2px solid currentColor}
.good{color:var(--good)}.warn{color:var(--warn)}.bad{color:var(--bad)}
.badge{display:inline-block;padding:1px 9px;border-radius:999px;border:1px solid currentColor;font-size:12px;font-weight:600}
.sign{display:flex;gap:32px;margin-top:28px}.sign div{flex:1}
@media print{body{background:#fff}.page{box-shadow:none;margin:0;padding:0;max-width:none}h2{break-after:avoid}table,section{break-inside:avoid}}`;

export function renderReport(snap: Snap, report: { id: number }): string {
  const type = snap.type as string;
  const body = type === 'compliance' ? complianceBody(snap) : type === 'sla' ? slaBody(snap) : type === 'security' ? securityBody(snap) : drBody(snap);
  const period = type === 'dr_readiness' ? `As at ${date(snap.generated_at)}` : `${day(snap.period.from)} to ${day(new Date(new Date(snap.period.to).getTime() - 1))}`;
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(TITLES[type])} - ${esc(snap.company.name)}</title><style>${CSS}</style></head><body><div class="page">
<header><h1>${esc(TITLES[type])}</h1><div>${esc(snap.company.name)}</div><div class="sub">${period} · Report ${esc(report.id)} · Generated ${date(snap.generated_at)}</div></header>
${body}${signature(snap.company.name)}</div></body></html>`;
}
