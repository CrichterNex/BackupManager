import { api, h, setUnauthorizedHandler, toast, errMessage, badge, table, label, fmtAgo, fmtDate, formModal, confirmModal } from './lib.js';

const METHODS = ['rsync', 'acronis', 'veeam', 'commvault', 'windows_manual_copy', 'linux_manual_copy', 'acronis_m365', 'microsoft_native_365', 'aws_backup', 'azure_backup'];
const TEST_TYPES = ['synthetic_file', 'random_restore', 'full_recovery', 'database_integrity', 'incremental_chain', 'm365_mailbox', 'recovery_point', 'file_permissions'];
const opts = (list) => list.map((v) => ({ value: v, label: label(v) }));
const ROLE_LABEL = { company_admin: 'Admin', company_manager: 'Manager', company_technician: 'Technician' };

let me = null; // { user, company, role }
const isAdmin = () => me.role === 'company_admin';
const canRunTests = () => me.role !== 'company_manager';

const PAGES = [
  { id: 'dashboard', title: 'Dashboard', render: dashboard },
  { id: 'servers', title: 'Servers', render: servers },
  { id: 'backups', title: 'Backups', render: backups },
  { id: 'tests', title: 'Tests', render: tests },
  { id: 'reports', title: 'Reports', render: reports, noTechnician: true },
  { id: 'users', title: 'Users', render: users, admin: true },
  { id: 'companies', title: 'Companies', render: companies, platform: true },
  { id: 'audit', title: 'Audit log', render: auditLog, admin: true },
  { id: 'profile', title: 'My profile', render: profile },
];

const visible = (p) => (!p.admin || isAdmin()) && (!p.noTechnician || me.role !== 'company_technician') && (!p.platform || me.platform);

const root = document.getElementById('app');
let unread = 0;

// --- routing. One company per user, so the slug in the URL is informational and always their own. ---
function currentPage() {
  const last = location.pathname.split('/').filter(Boolean).pop();
  return PAGES.find((p) => p.id === last && visible(p)) ?? PAGES[0];
}
function go(id) { history.pushState({}, '', `/companies/${me.company.company_slug}/${id}`); show(); }
window.addEventListener('popstate', () => me && show());

async function show() {
  const page = currentPage();
  const expected = `/companies/${me.company.company_slug}/${page.id}`;
  if (location.pathname !== expected) history.replaceState({}, '', expected);
  const view = h('main', { class: 'main', id: 'view' }, h('p', { class: 'muted' }, 'Loading…'));
  root.replaceChildren(h('div', { class: 'shell' }, sidebar(page), view));
  document.title = `${page.title} · ${me.company.company_name} · BMS`;
  try { view.replaceChildren(await page.render()); }
  catch (e) { view.replaceChildren(h('div', { class: 'error' }, errMessage(e))); }
  refreshUnread();
}

function sidebar(page) {
  return h('nav', { class: 'side', 'aria-label': 'Main' },
    h('div', { class: 'brand' }, h('strong', {}, me.company.company_name), h('span', {}, `${ROLE_LABEL[me.role]} · ${me.user.username}`)),
    PAGES.filter(visible).map((p) =>
      h('a', { class: `nav${p.id === page.id ? ' active' : ''}`, href: `/companies/${me.company.company_slug}/${p.id}`, 'aria-current': p.id === page.id ? 'page' : false,
        onclick: (e) => { e.preventDefault(); go(p.id); } }, p.title)),
    h('a', { class: 'nav', href: '#', id: 'bell', onclick: (e) => { e.preventDefault(); notifications(); } }, 'Notifications', h('span', { class: 'badge info', id: 'bell-count', hidden: true }, '0')),
    h('div', { class: 'spacer' }),
    h('button', { onclick: logout }, 'Log out'));
}

async function refreshUnread() {
  try {
    const r = await api('GET', '/notifications?unread=true&limit=1');
    unread = r.unread_count;
    const el = document.getElementById('bell-count');
    if (el) { el.textContent = unread; el.hidden = unread === 0; }
  } catch { /* the badge is a convenience; ignore */ }
}

const topbar = (title, subtitle, ...actions) => h('div', { class: 'topbar' }, h('div', {}, h('h1', {}, title), subtitle && h('p', {}, subtitle)), h('div', { class: 'row' }, actions));
const card = (title, ...body) => h('section', { class: 'card' }, title && h('h2', { style: 'margin-bottom:12px' }, title), body);
const act = async (fn, ok) => { try { await fn(); if (ok) toast(ok); return true; } catch (e) { toast(errMessage(e), true); return false; } };

// --- login ---
function loginScreen() {
  me = null;
  const err = h('div', { class: 'error', hidden: true, role: 'alert' });
  const user = h('input', { id: 'u', autocomplete: 'username', required: true });
  const pass = h('input', { id: 'p', type: 'password', autocomplete: 'current-password', required: true });
  const btn = h('button', { class: 'primary', type: 'submit', style: 'width:100%' }, 'Log in');
  const form = h('form', { onsubmit: async (e) => {
    e.preventDefault(); btn.disabled = true; err.hidden = true;
    try {
      const r = await api('POST', '/auth/login', { username: user.value.trim(), password: pass.value });
      me = { user: r.user, company: r.company, role: r.role, platform: r.platform_admin };
      show();
    } catch (ex) { err.hidden = false; err.textContent = ex.message; btn.disabled = false; pass.value = ''; pass.focus(); }
  } },
  err, h('div', { class: 'field' }, h('label', { for: 'u' }, 'Username'), user), h('div', { class: 'field' }, h('label', { for: 'p' }, 'Password'), pass), btn);
  root.replaceChildren(h('div', { class: 'login' }, h('div', { class: 'card' }, h('h1', {}, 'Backup Management'), h('p', { class: 'muted' }, 'Log in to your company account'), form)));
  user.focus();
}

async function logout() { await api('POST', '/auth/logout').catch(() => {}); history.replaceState({}, '', '/'); loginScreen(); }
setUnauthorizedHandler(() => { if (me) { toast('Your session has ended. Please log in again.', true); history.replaceState({}, '', '/'); loginScreen(); } });

// --- dashboard ---
async function dashboard() {
  const d = (await api('GET', '/dashboard')).data;
  const pct = (t) => (t.total ? `${Math.round((t.passed / t.total) * 100)}%` : '—');
  const score = d.health_score;
  const tone = score == null ? '' : score >= 90 ? 'ok' : score >= 70 ? 'warn' : 'bad';
  const b = d.backups_30d;
  return h('div', {},
    topbar('Dashboard', d.company.name),
    h('div', { class: 'grid' },
      metric('Health score', score ?? '—', tone, score == null ? 'No backups or tests yet' : 'Last 30 days'),
      metric('Servers', d.servers.total, '', `${d.servers.failing} with failures`, d.servers.failing ? 'bad' : ''),
      metric('Backups (30d)', b.finished, '', `${b.success} ok · ${b.warning} warning · ${b.failed} failed`),
      metric('Tests passed (7d)', pct(d.tests.last_7d), '', `${d.tests.last_7d.passed} of ${d.tests.last_7d.total}`),
      metric('Open test failures', d.unresolved_failures, d.unresolved_failures ? 'bad' : 'ok', d.unresolved_failures ? 'Need review' : 'All clear')),
    card('Recent backups', table([
      { title: 'Server', render: (r) => r.hostname }, { title: 'Method', render: (r) => label(r.backup_method) },
      { title: 'Status', render: (r) => badge(r.status) }, { title: 'Size', render: (r) => (r.size_gb != null ? `${r.size_gb} GB` : '—') },
      { title: 'Completed', render: (r) => fmtAgo(r.completed_time) },
    ], d.recent_backups, { empty: 'No completed backups yet.' })));
}
const metric = (name, value, tone, sub) => h('div', { class: 'metric' }, h('div', { class: 'label' }, name), h('div', { class: `value ${tone}` }, value), h('div', { class: 'small muted' }, sub));

// --- servers ---
async function servers() {
  const f = { search: '', os: '', status: '' };
  const holder = h('div');
  const load = async () => {
    const q = new URLSearchParams({ limit: 200, sort_by: 'hostname', sort_order: 'asc' });
    for (const [k, v] of Object.entries(f)) if (v) q.set(k, v);
    const r = await api('GET', `/servers?${q}`);
    holder.replaceChildren(table([
      { title: 'Hostname', render: (s) => s.hostname }, { title: 'OS', render: (s) => label(s.os_type) },
      { title: 'Criticality', render: (s) => label(s.criticality) }, { title: 'Methods', render: (s) => s.backup_methods.map(label).join(', ') || '—' },
      { title: 'Last backup', render: (s) => (s.last_backup_status ? [badge(s.last_backup_status), ' ', h('span', { class: 'small muted' }, fmtAgo(s.last_backup_time))] : '—') },
      { title: 'Last test', render: (s) => (s.last_test_status ? badge(s.last_test_status) : '—') },
      { title: 'Health', render: (s) => badge(s.status) },
    ], r.data, { onRow: serverDetail, empty: 'No servers match.' }));
  };
  const timer = { t: null };
  const filters = h('div', { class: 'row filters' },
    h('input', { type: 'search', placeholder: 'Search hostname', 'aria-label': 'Search hostname', oninput: (e) => { f.search = e.target.value; clearTimeout(timer.t); timer.t = setTimeout(load, 250); } }),
    select('OS', 'os', f, load, [['', 'All OS'], ['linux', 'Linux'], ['windows', 'Windows']]),
    select('Health', 'status', f, load, [['', 'All health'], ['healthy', 'Healthy'], ['failing', 'Failing'], ['pending_test', 'Pending test'], ['unknown', 'Unknown']]));
  await load();
  return h('div', {}, topbar('Servers', null, isAdmin() && h('button', { class: 'primary', onclick: () => serverForm() }, 'Add server')), card(null, filters, holder));
}
const select = (name, key, state, load, options) =>
  h('select', { 'aria-label': name, onchange: (e) => { state[key] = e.target.value; load(); } }, options.map(([v, t]) => h('option', { value: v }, t)));

const serverFields = (s = {}) => [
  { name: 'hostname', label: 'Hostname', required: true, value: s.hostname },
  { name: 'ip_address', label: 'IP address', value: s.ip_address ?? '' },
  { name: 'os_type', label: 'Operating system', type: 'select', options: [{ value: 'linux', label: 'Linux' }, { value: 'windows', label: 'Windows' }], value: s.os_type },
  { name: 'criticality', label: 'Criticality', type: 'select', options: opts(['standard', 'critical', 'non-critical']), value: s.criticality },
  { name: 'backup_methods', label: 'Backup methods', type: 'checks', options: opts(METHODS), value: s.backup_methods ?? [] },
  { name: 'retention_policy_days', label: 'Retention (days)', type: 'number', value: s.retention_policy_days ?? '', hint: 'Leave empty to keep indefinitely' },
  { name: 'notes', label: 'Notes', type: 'textarea', value: s.notes ?? '' },
];
const serverBody = (v) => ({
  hostname: v.hostname.trim(), ip_address: v.ip_address.trim() || null, os_type: v.os_type, criticality: v.criticality,
  backup_methods: v.backup_methods, retention_policy_days: v.retention_policy_days ? Number(v.retention_policy_days) : null, notes: v.notes.trim() || null,
});
async function serverForm(existing) {
  const done = await formModal({
    title: existing ? `Edit ${existing.hostname}` : 'Add server', fields: serverFields(existing),
    onSubmit: (v) => (existing ? api('PATCH', `/servers/${existing.id}`, serverBody(v)) : api('POST', '/servers', serverBody(v))),
  });
  if (done) { toast(existing ? 'Server updated' : 'Server added'); show(); }
}

async function serverDetail(s) {
  const full = (await api('GET', `/servers/${s.id}`)).data;
  const techs = isAdmin() ? (await api('GET', '/users')).data.filter((u) => u.role === 'company_technician' && u.active) : [];
  const assigned = new Set(full.assigned_technicians.map((t) => t.id));
  const overlay = h('div', { class: 'overlay', onclick: (e) => e.target === overlay && overlay.remove() });
  const row = (k, v) => h('tr', {}, h('th', {}, k), h('td', {}, v));
  overlay.append(h('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true' },
    h('h2', {}, full.hostname),
    h('table', {}, h('tbody', {},
      row('Health', badge(full.status)), row('OS', label(full.os_type)), row('IP', full.ip_address ?? '—'), row('Criticality', label(full.criticality)),
      row('Methods', full.backup_methods.map(label).join(', ') || '—'), row('Retention', full.retention_policy_days ? `${full.retention_policy_days} days` : 'Indefinite'),
      row('Last backup', `${label(full.last_backup_status)} · ${fmtDate(full.last_backup_time)}`), row('Last test', `${label(full.last_test_status)} · ${fmtDate(full.last_test_time)}`),
      row('Technicians', full.assigned_technicians.map((t) => t.username).join(', ') || 'None assigned'), row('Notes', full.notes ?? '—'))),
    h('div', { class: 'actions' },
      isAdmin() && h('button', { class: 'danger', onclick: async () => {
        if (await confirmModal('Delete server?', `${full.hostname} and its backup jobs will be removed from view. History is kept for audit.`, 'Delete')) {
          overlay.remove(); if (await act(() => api('DELETE', `/servers/${full.id}`), 'Server deleted')) show();
        } } }, 'Delete'),
      isAdmin() && h('button', { onclick: async () => { overlay.remove(); await assignForm(full, techs, assigned); } }, 'Assign technicians'),
      isAdmin() && h('button', { onclick: () => { overlay.remove(); serverForm(full); } }, 'Edit'),
      h('button', { class: 'primary', onclick: () => overlay.remove() }, 'Close'))));
  document.body.append(overlay);
}
async function assignForm(server, techs, assigned) {
  if (!techs.length) return toast('Create a technician user first (Users page).', true);
  const done = await formModal({
    title: `Technicians for ${server.hostname}`, submitLabel: 'Save',
    fields: [{ name: 'ids', label: 'Technicians', type: 'checks', options: techs.map((t) => ({ value: String(t.id), label: t.username })), value: [...assigned].map(String) }],
    onSubmit: (v) => api('PUT', `/servers/${server.id}/assignments`, { user_ids: v.ids.map(Number) }),
  });
  if (done) { toast('Assignments saved'); show(); }
}

// --- backups ---
async function backups() {
  const [jobs, srv] = await Promise.all([api('GET', '/backup-jobs?limit=200'), api('GET', '/servers?limit=500')]);
  const addBtn = isAdmin() && h('button', { class: 'primary', onclick: () => jobForm(srv.data) }, 'New backup job');
  return h('div', {}, topbar('Backups', null, addBtn), card(null, table([
    { title: 'Server', render: (j) => j.hostname }, { title: 'Method', render: (j) => label(j.backup_method) },
    { title: 'Schedule', render: (j) => (j.schedule ? h('code', {}, j.schedule) : '—') }, { title: 'Status', render: (j) => badge(j.status) },
    { title: 'Size', render: (j) => (j.size_gb != null ? `${j.size_gb} GB` : '—') }, { title: 'Last run', render: (j) => fmtAgo(j.completed_time) },
    { title: 'Tested', render: (j) => (j.test_status ? badge(j.test_status) : '—') },
    { title: '', render: (j) => h('span', { class: 'row' },
      canRunTests() && h('button', { class: 'link', onclick: (e) => { e.stopPropagation(); runTest(j); } }, 'Run test'),
      isAdmin() && h('button', { class: 'link', onclick: (e) => { e.stopPropagation(); setStatus(j); } }, 'Update status'),
      isAdmin() && h('button', { class: 'link', onclick: async (e) => { e.stopPropagation(); if (await confirmModal('Delete backup job?', `${j.hostname} / ${label(j.backup_method)}`, 'Delete') && await act(() => api('DELETE', `/backup-jobs/${j.id}`), 'Job deleted')) show(); } }, 'Delete')) },
  ], jobs.data, { empty: 'No backup jobs yet.' })));
}
async function jobForm(serverList) {
  if (!serverList.length) return toast('Add a server first.', true);
  const done = await formModal({
    title: 'New backup job',
    fields: [
      { name: 'server_id', label: 'Server', type: 'select', options: serverList.map((s) => ({ value: s.id, label: s.hostname })) },
      { name: 'backup_method', label: 'Method', type: 'select', options: opts(METHODS), hint: 'The server must have this method enabled.' },
      { name: 'schedule', label: 'Schedule (cron)', value: '0 2 * * *', hint: 'minute hour day month weekday, e.g. 0 2 * * * = 2:00 daily' },
      { name: 'retention_days', label: 'Retention (days)', type: 'number', value: '90' },
    ],
    onSubmit: (v) => api('POST', '/backup-jobs', { server_id: Number(v.server_id), backup_method: v.backup_method, schedule: v.schedule.trim() || undefined, retention_days: v.retention_days ? Number(v.retention_days) : undefined }),
  });
  if (done) { toast('Backup job created'); show(); }
}
async function setStatus(j) {
  const done = await formModal({
    title: `Update ${j.hostname} / ${label(j.backup_method)}`,
    fields: [
      { name: 'status', label: 'Status', type: 'select', options: opts(['scheduled', 'running', 'success', 'warning', 'failed']), value: j.status },
      { name: 'size_gb', label: 'Size (GB)', type: 'number', value: j.size_gb ?? '' },
      { name: 'status_message', label: 'Message', type: 'textarea', value: j.status_message ?? '' },
    ],
    onSubmit: (v) => api('PATCH', `/backup-jobs/${j.id}`, { status: v.status, size_gb: v.size_gb ? Number(v.size_gb) : null, status_message: v.status_message.trim() || null }),
  });
  if (done) { toast('Status updated'); show(); }
}
async function runTest(j) {
  const done = await formModal({
    title: `Queue test for ${j.hostname}`, submitLabel: 'Queue test',
    fields: [{ name: 'test_type', label: 'Test type', type: 'select', options: opts(TEST_TYPES) }],
    onSubmit: (v) => api('POST', `/backup-jobs/${j.id}/test`, { test_type: v.test_type }),
  });
  if (done) { toast('Test queued'); show(); }
}

// --- tests and failures ---
async function tests() {
  const [t, f] = await Promise.all([api('GET', '/tests?limit=100'), api('GET', '/test-failures?resolved=false&limit=100')]);
  const canResolve = me.role !== 'company_manager';
  return h('div', {}, topbar('Tests'),
    card(`Open failures (${f.pagination.total_count})`, table([
      { title: 'Server', render: (x) => x.hostname }, { title: 'Test', render: (x) => label(x.test_type) },
      { title: 'Type', render: (x) => label(x.failure_type) }, { title: 'Severity', render: (x) => badge(x.severity) },
      { title: 'Item', render: (x) => x.failed_item ?? '—' }, { title: 'When', render: (x) => fmtAgo(x.created_at) },
      { title: '', render: (x) => canResolve && h('button', { class: 'link', onclick: () => resolveForm(x) }, 'Resolve') },
    ], f.data, { empty: 'No open failures.' })),
    card('Test history', table([
      { title: 'Server', render: (x) => x.hostname }, { title: 'Type', render: (x) => label(x.test_type) }, { title: 'Status', render: (x) => badge(x.test_status === 'success' ? 'passed' : x.test_status, label(x.test_status)) },
      { title: 'Hash match', render: (x) => (x.hash_match == null ? '—' : x.hash_match ? 'Yes' : 'No') }, { title: 'Duration', render: (x) => (x.duration_seconds != null ? `${x.duration_seconds}s` : '—') },
      { title: 'Queued', render: (x) => fmtAgo(x.created_at) },
    ], t.data, { empty: 'No tests have run yet.' })));
}
async function resolveForm(failure) {
  const done = await formModal({
    title: `Resolve failure on ${failure.hostname}`, submitLabel: 'Resolve',
    fields: [
      { name: 'resolution_type', label: 'Resolution', type: 'select', options: opts(['retest_passed', 'backup_rerun', 'backup_fixed', 'false_positive', 'known_issue']) },
      { name: 'backup_usable', label: 'Is the backup still usable?', type: 'select', options: [{ value: 'true', label: 'Yes' }, { value: 'false', label: 'No' }] },
      { name: 'notes', label: 'Notes', type: 'textarea', required: true, hint: 'Recorded in the audit log.' },
    ],
    onSubmit: (v) => api('POST', `/test-failures/${failure.id}/resolve`, { resolution_type: v.resolution_type, backup_usable: v.backup_usable === 'true', notes: v.notes }),
  });
  if (done) { toast('Failure resolved'); show(); }
}

// --- reports (admin, manager) ---
const REPORT_LABEL = { compliance: 'Compliance', sla: 'SLA performance', security: 'Security and access', dr_readiness: 'DR readiness' };
const DAY_MS = 86400000;
const PERIODS = {
  last_month: { label: 'Last calendar month', range: () => { const n = new Date(); return [Date.UTC(n.getUTCFullYear(), n.getUTCMonth() - 1, 1), Date.UTC(n.getUTCFullYear(), n.getUTCMonth(), 1)]; } },
  last_quarter: { label: 'Last calendar quarter', range: () => { const n = new Date(); const q = Math.floor(n.getUTCMonth() / 3); return [Date.UTC(n.getUTCFullYear(), (q - 1) * 3, 1), Date.UTC(n.getUTCFullYear(), q * 3, 1)]; } },
  last_30: { label: 'Last 30 days', range: () => [Date.now() - 30 * DAY_MS, Date.now() + 60000] },
  last_90: { label: 'Last 90 days', range: () => [Date.now() - 90 * DAY_MS, Date.now() + 60000] },
};
async function reports() {
  const r = await api('GET', '/reports?limit=100');
  return h('div', {}, topbar('Reports', 'Each report is saved as a snapshot. Open one and use your browser’s Print, then Save as PDF.', h('button', { class: 'primary', onclick: reportForm }, 'Generate report')),
    card(null, table([
      { title: 'Report', render: (x) => REPORT_LABEL[x.report_type] },
      { title: 'Period', render: (x) => (x.report_type === 'dr_readiness' ? 'As at generation' : `${fmtDate(x.period_start)} to ${fmtDate(new Date(new Date(x.period_end) - 1))}`) },
      { title: 'Generated', render: (x) => `${fmtDate(x.created_at)}${x.generated_by ? '' : ' (automatic)'}` },
      { title: '', render: (x) => h('a', { href: `/api/reports/${x.id}/view`, target: '_blank', rel: 'noopener' }, 'Open') },
    ], r.data, { empty: 'No reports yet. Generate one, or wait for the monthly and quarterly ones.' })));
}
async function reportForm() {
  const done = await formModal({
    title: 'Generate report', submitLabel: 'Generate',
    fields: [
      { name: 'report_type', label: 'Report', type: 'select', options: Object.entries(REPORT_LABEL).map(([value, label]) => ({ value, label })) },
      { name: 'period', label: 'Period', type: 'select', options: Object.entries(PERIODS).map(([value, p]) => ({ value, label: p.label })), hint: 'Ignored for DR readiness, which is a snapshot of right now.' },
    ],
    onSubmit: (v) => {
      const [from, to] = PERIODS[v.period].range();
      return api('POST', '/reports', v.report_type === 'dr_readiness' ? { report_type: v.report_type } : { report_type: v.report_type, period_start: new Date(from).toISOString(), period_end: new Date(to).toISOString() });
    },
  });
  if (done) { toast('Report generated'); show(); }
}

// --- companies (platform admins only) ---
async function companies() {
  const r = await api('GET', '/platform/companies');
  return h('div', {}, topbar('Companies', 'Every company on this system. You only see counts here, never another company’s records.', h('button', { class: 'primary', onclick: companyForm }, 'Create company')),
    card(null, table([
      { title: 'Company', render: (c) => c.name }, { title: 'Slug', render: (c) => c.slug }, { title: 'Plan', render: (c) => label(c.subscription_tier) },
      { title: 'Users', render: (c) => c.users }, { title: 'Servers', render: (c) => `${c.servers}${c.max_servers ? ` / ${c.max_servers}` : ''}` },
      { title: 'Status', render: (c) => badge(c.is_active ? 'success' : 'failed', c.is_active ? 'Active' : 'Disabled') }, { title: 'Created', render: (c) => fmtDate(c.created_at) },
    ], r.data)));
}
async function companyForm() {
  let created = null;
  const done = await formModal({
    title: 'Create company', submitLabel: 'Create',
    fields: [
      { name: 'name', label: 'Company name', required: true },
      { name: 'slug', label: 'Slug', required: true, hint: 'Lowercase letters, digits and hyphens, e.g. acme-corp. Used in the web address.' },
      { name: 'subscription_tier', label: 'Plan', type: 'select', options: opts(['basic', 'pro', 'enterprise']) },
      { name: 'max_servers', label: 'Server limit', type: 'number', hint: 'Leave empty for no limit.' },
      { name: 'admin_username', label: 'First admin username', required: true },
      { name: 'admin_email', label: 'First admin email', type: 'email', required: true },
      { name: 'admin_password', label: 'First admin password', type: 'password', hint: 'At least 12 characters. Leave empty to generate one.' },
    ],
    onSubmit: async (v) => {
      created = await api('POST', '/platform/companies', {
        name: v.name.trim(), slug: v.slug.trim(), subscription_tier: v.subscription_tier,
        max_servers: v.max_servers ? Number(v.max_servers) : null,
        admin_username: v.admin_username.trim(), admin_email: v.admin_email.trim(), admin_password: v.admin_password || null,
      });
    },
  });
  if (!done) return;
  if (created.admin.temporary_password) {
    await confirmModal(`${created.data.name} created`, `Give this temporary password to ${created.admin.username}. It is shown only once: ${created.admin.temporary_password}`, 'Done');
  } else toast('Company created');
  show();
}

// --- users (admin) ---
async function users() {
  const r = await api('GET', '/users');
  return h('div', {}, topbar('Users', `People in ${me.company.company_name}`, h('button', { class: 'primary', onclick: userForm }, 'Add user')),
    card(null, table([
      { title: 'Username', render: (u) => u.username }, { title: 'Email', render: (u) => u.email }, { title: 'Role', render: (u) => ROLE_LABEL[u.role] },
      { title: 'Status', render: (u) => badge(u.active ? 'success' : 'failed', u.active ? 'Active' : 'Disabled') }, { title: 'Last login', render: (u) => fmtAgo(u.last_login) },
      { title: '', render: (u) => u.username === me.user.username ? h('span', { class: 'small muted' }, 'You') : h('span', { class: 'row' },
        h('button', { class: 'link', onclick: () => roleForm(u) }, 'Change role'),
        h('button', { class: 'link', onclick: () => resetPw(u) }, 'Reset password'),
        h('button', { class: 'link', onclick: async () => { if (await act(() => api('PATCH', `/users/${u.id}`, { active: !u.active }), u.active ? 'User disabled' : 'User enabled')) show(); } }, u.active ? 'Disable' : 'Enable')) },
    ], r.data)));
}
async function userForm() {
  const done = await formModal({
    title: 'Add user',
    fields: [
      { name: 'username', label: 'Username', required: true }, { name: 'email', label: 'Email', type: 'email', required: true },
      { name: 'first_name', label: 'First name' }, { name: 'last_name', label: 'Last name' },
      { name: 'role', label: 'Role', type: 'select', options: Object.entries(ROLE_LABEL).map(([value, l]) => ({ value, label: l })) },
      { name: 'password', label: 'Temporary password', type: 'password', required: true, hint: 'At least 12 characters. They can change it after logging in.' },
    ],
    onSubmit: (v) => api('POST', '/users', { username: v.username.trim(), email: v.email.trim(), first_name: v.first_name.trim() || undefined, last_name: v.last_name.trim() || undefined, role: v.role, password: v.password }),
  });
  if (done) { toast('User created'); show(); }
}
async function roleForm(u) {
  const done = await formModal({
    title: `Role for ${u.username}`,
    fields: [{ name: 'role', label: 'Role', type: 'select', options: Object.entries(ROLE_LABEL).map(([value, l]) => ({ value, label: l })), value: u.role }],
    onSubmit: (v) => api('PATCH', `/users/${u.id}`, { role: v.role }),
  });
  if (done) { toast('Role updated'); show(); }
}
async function resetPw(u) {
  const done = await formModal({
    title: `Reset password for ${u.username}`, submitLabel: 'Reset',
    fields: [{ name: 'password', label: 'New password', type: 'password', required: true, hint: 'At least 12 characters.' }],
    onSubmit: (v) => api('PATCH', `/users/${u.id}`, { password: v.password }),
  });
  if (done) toast('Password reset');
}

// --- audit (admin) ---
async function auditLog() {
  const r = await api('GET', '/audit?limit=200');
  return h('div', {}, topbar('Audit log', 'Every change in your company, newest first'),
    card(null, table([
      { title: 'When', render: (a) => fmtDate(a.created_at) }, { title: 'User', render: (a) => a.username ?? '—' },
      { title: 'Action', render: (a) => [label(a.action), a.success ? '' : ' (failed)'] }, { title: 'Resource', render: (a) => (a.resource_type ? `${label(a.resource_type)} #${a.resource_id ?? ''}` : '—') },
      { title: 'IP', render: (a) => a.ip_address ?? '—' },
    ], r.data)));
}

// --- profile + notifications ---
async function profile() {
  return h('div', {}, topbar('My profile'),
    card(null, h('table', {}, h('tbody', {}, ...[['Username', me.user.username], ['Email', me.user.email], ['Company', me.company.company_name], ['Role', ROLE_LABEL[me.role]]].map(([k, v]) => h('tr', {}, h('th', {}, k), h('td', {}, v))))),
      h('div', { style: 'margin-top:14px' }, h('button', { onclick: changePw }, 'Change password'))));
}
async function changePw() {
  const done = await formModal({
    title: 'Change password', submitLabel: 'Change',
    fields: [{ name: 'current', label: 'Current password', type: 'password', required: true }, { name: 'next', label: 'New password', type: 'password', required: true, hint: 'At least 12 characters.' }],
    onSubmit: (v) => api('POST', '/me/change-password', { current_password: v.current, new_password: v.next }),
  });
  if (done) toast('Password changed');
}
async function notifications() {
  const r = await api('GET', '/notifications?limit=30');
  const overlay = h('div', { class: 'overlay', onclick: (e) => e.target === overlay && overlay.remove() });
  overlay.append(h('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true' }, h('h2', {}, 'Notifications'),
    r.data.length ? r.data.map((n) => h('div', { class: 'field' }, h('div', {}, h('strong', {}, n.subject ?? label(n.event_type)), ' ', n.is_read ? '' : badge('info', 'New')), h('div', {}, n.message), h('div', { class: 'small muted' }, fmtAgo(n.created_at)))) : h('div', { class: 'empty' }, 'No notifications.'),
    h('div', { class: 'actions' }, h('button', { onclick: async () => { await act(() => api('POST', '/notifications/read-all')); overlay.remove(); refreshUnread(); } }, 'Mark all read'), h('button', { class: 'primary', onclick: () => overlay.remove() }, 'Close'))));
  document.body.append(overlay);
}

// --- boot: resume an existing session via the cookie ---
(async () => {
  try {
    const r = (await api('GET', '/me')).data;
    me = { user: { username: r.username, email: r.email }, company: { company_name: r.company_name, company_slug: r.company_slug }, role: r.role, platform: r.platform_admin };
    show();
  } catch { loginScreen(); }
})();
