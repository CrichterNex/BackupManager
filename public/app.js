import { api, getActing, setActing, h, setUnauthorizedHandler, toast, errMessage, badge, table, label, fmtAgo, fmtDate, formModal, confirmModal } from './lib.js';

const METHODS = ['rsync', 'robocopy', 'windows_manual_copy', 'linux_manual_copy', 'manual_copy', 'acronis', 'veeam', 'commvault', 'acronis_m365', 'microsoft_native_365', 'aws_backup', 'azure_backup'];
// BMS-managed copies go to a backup location. Every other method belongs to a platform that has its own locations.
const LOCATION_METHODS = ['rsync', 'robocopy', 'windows_manual_copy', 'linux_manual_copy', 'manual_copy'];
const LOCATION_KINDS = ['local_disk', 'network_share', 'nas', 'external_drive', 'offsite', 'other'];
const TEST_TYPES = ['synthetic_file', 'random_restore', 'full_recovery', 'database_integrity', 'incremental_chain', 'm365_mailbox', 'recovery_point', 'file_permissions'];
const opts = (list) => list.map((v) => ({ value: v, label: label(v) }));
const ROLE_LABEL = { company_admin: 'Admin', company_manager: 'Manager', company_technician: 'Technician' };

let me = null; // { user, company, role }
const isAdmin = () => me.role === 'company_admin';
const canRunTests = () => me.role !== 'company_manager';

const PAGES = [
  { id: 'dashboard', title: 'Dashboard', render: dashboard },
  { id: 'servers', title: 'Servers', render: servers },
  { id: 'agents', title: 'Agents', render: agents },
  { id: 'backups', title: 'Backups', render: backups },
  { id: 'locations', title: 'Locations', render: locations },
  { id: 'tests', title: 'Tests', render: tests },
  { id: 'reports', title: 'Reports', render: reports, noTechnician: true },
  { id: 'users', title: 'Users', render: users, admin: true },
  { id: 'integrations', title: 'Integrations', render: integrations, admin: true },
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
    me.acting && h('div', { class: 'error', role: 'status', style: 'margin:8px 0' }, `Working inside ${me.company.company_name} as a platform admin. Your changes are recorded in its audit log. `,
      h('button', { class: 'link', onclick: () => { setActing(null); location.assign('/'); } }, 'Back to my company')),
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

async function logout() { await api('POST', '/auth/logout').catch(() => {}); setActing(null); history.replaceState({}, '', '/'); loginScreen(); }
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
    select('Health', 'status', f, load, [['', 'All health'], ['healthy', 'Healthy'], ['failing', 'Failing'], ['pending_test', 'Pending test'], ['test_overdue', 'Test overdue'], ['unknown', 'Unknown']]));
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
      row('Technicians', full.assigned_technicians.map((t) => t.username).join(', ') || 'None assigned'), row('Agent', agentSummary(full.agent)), row('Platforms', linksSummary(full.links)), row('Notes', full.notes ?? '—'))),
    h('div', { class: 'actions' },
      isAdmin() && h('button', { class: 'danger', onclick: async () => {
        if (await confirmModal('Delete server?', `${full.hostname} and its backup jobs will be removed from view. History is kept for audit.`, 'Delete')) {
          overlay.remove(); if (await act(() => api('DELETE', `/servers/${full.id}`), 'Server deleted')) show();
        } } }, 'Delete'),
      isAdmin() && (full.agent.enrolled
        ? h('button', { onclick: async () => { if (await confirmModal('Remove agent?', `${full.hostname} will stop receiving backup and test commands. The agent software on the server keeps running until you stop it.`, 'Remove') && await act(() => api('DELETE', `/servers/${full.id}/agent`), 'Agent removed')) { overlay.remove(); show(); } } }, 'Remove agent')
        : h('button', { onclick: () => { overlay.remove(); enrollAgent(full); } }, 'Enroll agent')),
      isAdmin() && h('button', { onclick: () => { overlay.remove(); linkForm(full); } }, 'Link to platform device'),
      isAdmin() && full.links.length > 0 && h('button', { onclick: async () => {
        if (await confirmModal('Remove platform link?', 'BMS will stop reading backup status for this server from the platform. Past results stay.', 'Remove')
          && await act(async () => { for (const l of full.links) await api('DELETE', `/servers/${full.id}/links/${l.id}`); }, 'Link removed')) { overlay.remove(); show(); } } }, 'Remove platform link'),
      isAdmin() && h('button', { onclick: async () => { overlay.remove(); await assignForm(full, techs, assigned); } }, 'Assign technicians'),
      isAdmin() && h('button', { onclick: () => { overlay.remove(); serverForm(full); } }, 'Edit'),
      h('button', { class: 'primary', onclick: () => overlay.remove() }, 'Close'))));
  document.body.append(overlay);
}
const linksSummary = (links) => (links.length
  ? h('span', {}, links.map((l) => h('div', {},
    `${l.integration.name}: ${l.external_name ?? l.external_id} · ${l.last_status ? label(l.last_status) : 'not read yet'}`,
    l.last_success_at ? ` · last success ${fmtAgo(l.last_success_at)}` : '',
    l.sync_error ? h('div', { class: 'small' }, l.sync_error) : '')))
  : 'None');
async function linkForm(server) {
  let list;
  try { list = (await api('GET', '/integrations')).data; } catch (e) { return toast(errMessage(e), true); }
  if (!list.length) return toast('Add a platform connection first, on the Integrations page.', true);
  let integrationId = String(list[0].id);
  if (list.length > 1) {
    const chosen = await new Promise((resolve) => {
      formModal({
        title: `Link ${server.hostname}`, submitLabel: 'Next',
        fields: [{ name: 'integration_id', label: 'Platform connection', type: 'select', options: list.map((i) => ({ value: String(i.id), label: i.name })) }],
        onSubmit: async (v) => { resolve(v.integration_id); },
      }).then(() => resolve(null));
    });
    if (!chosen) return;
    integrationId = chosen;
  }
  let devices;
  try { devices = (await api('GET', `/integrations/${integrationId}/devices`)).data; } catch (e) { return toast(errMessage(e), true); }
  const free = devices.filter((d) => !d.linked_server_id);
  if (!free.length) return toast('Every device on that connection is already linked, or none were found.', true);
  const done = await formModal({
    title: `Link ${server.hostname} to a device`, submitLabel: 'Link',
    fields: [{ name: 'external_id', label: 'Device on the platform', type: 'select', options: free.map((d) => ({ value: d.id, label: `${d.name}${d.status ? ` (${d.status})` : ''}` })), hint: 'BMS will read this device’s backup status from the platform.' }],
    onSubmit: (v) => api('POST', `/servers/${server.id}/links`, { integration_id: Number(integrationId), external_id: v.external_id }),
  });
  if (done) { toast('Linked. Status read from the platform.'); show(); }
}
const agentSummary = (a) => {
  if (!a.enrolled) return 'Not enrolled';
  const actions = Object.keys(a.actions ?? {});
  return h('span', {}, badge(a.online ? 'success' : 'failed', a.online ? 'Online' : 'Offline'), ` last seen ${fmtAgo(a.last_seen_at)}`,
    a.agent_version ? ` · v${a.agent_version}` : '', actions.length ? ` · actions: ${actions.join(', ')}` : ' · no actions reported yet',
    (a.locations ?? []).length ? ` · locations: ${a.locations.join(', ')}` : '');
};
// --- agents ---
async function agents() {
  const d = (await api('GET', '/agents')).data;
  const c = d.counts;
  const summary = c.total
    ? `${c.total} agent${c.total === 1 ? '' : 's'}: ${c.online} online, ${c.offline} offline${c.outdated ? `, ${c.outdated} need an update` : ''}.`
    : 'No agents yet.';
  const names = (o) => Object.keys(o ?? {}).join(', ') || '—';
  return h('div', {}, topbar('Agents', 'Small programs on your servers that run backups and restore tests when BMS asks. Each runs as a service that starts at boot and only ever calls BMS.'),
    h('p', { class: c.offline || c.outdated ? '' : 'muted', style: 'margin:0 0 12px' }, summary),
    card('Agents', table([
      { title: 'Server', render: (a) => h('button', { class: 'link', onclick: () => serverDetail({ id: a.server_id }) }, a.hostname) },
      { title: 'Status', render: (a) => h('span', {}, badge(a.online ? 'success' : 'failed', a.online ? 'Online' : 'Offline'),
        a.running_commands ? ' working' : (a.pending_commands ? ` ${a.pending_commands} waiting` : '')) },
      { title: 'Last seen', render: (a) => fmtAgo(a.last_seen_at) },
      { title: 'Version', render: (a) => h('span', {}, a.agent_version ? `v${a.agent_version}` : '—', a.outdated ? h('span', { style: 'margin-left:6px' }, badge('warning', 'Update available')) : '') },
      { title: 'System', render: (a) => a.os_info ?? '—' },
      { title: 'Actions', render: (a) => names(a.actions) },
      { title: 'Locations', render: (a) => (a.locations ?? []).join(', ') || '—' },
    ], d.agents, { empty: 'No agents yet. Enroll one for a server below.' })),
    card(`Servers without an agent (${d.servers_without_agent.length})`, table([
      { title: 'Server', render: (s) => s.hostname }, { title: 'OS', render: (s) => label(s.os_type) },
      { title: '', render: (s) => isAdmin() && h('button', { class: 'link', onclick: () => enrollAgent(s) }, 'Enroll and get install command') },
    ], d.servers_without_agent, { empty: 'Every server has an agent.' })),
    card('The agent program', d.release.available
      ? h('div', {},
        h('p', {}, `Version ${d.release.version ?? '?'} · `, h('a', { href: d.release.path, download: 'bms_agent.py' }, 'Download bms_agent.py'), ' · SHA-256 ', h('code', { style: 'word-break:break-all' }, d.release.sha256)),
        h('p', { class: 'small muted' }, 'Easiest: enroll a server above and run the command it gives you. It installs the agent as a service (a systemd service on Linux, a scheduled task running as SYSTEM at boot on Windows). To update an agent, download the new file onto that server and run: python bms_agent.py --upgrade. To remove one: python bms_agent.py --uninstall, then remove it here.'))
      : h('p', { class: 'muted' }, 'The agent file is not on this BMS server.')));
}

// Copies text to the clipboard, falling back to selecting it when the browser blocks clipboard access.
async function copyText(text) {
  try { await navigator.clipboard.writeText(text); toast('Copied'); } catch { toast('Select the text and copy it with Ctrl+C', true); }
}
const codeBlock = (title, text) => h('div', { style: 'margin:10px 0' },
  h('div', { class: 'row', style: 'justify-content:space-between;align-items:center' }, h('strong', {}, title), h('button', { class: 'link', onclick: () => copyText(text) }, 'Copy')),
  h('pre', { style: 'white-space:pre-wrap;word-break:break-all;background:rgba(127,127,127,.15);padding:10px;border-radius:6px;font-size:12px;user-select:all' }, text));

async function enrollAgent(server) {
  let r;
  try { r = await api('POST', `/servers/${server.id}/agent`); } catch (e) { return toast(errMessage(e), true); }
  const config = JSON.stringify({
    bms_url: r.agent_config.bms_url, token: r.agent_config.token, poll_seconds: 30,
    actions: {
      backup: { type: 'command', command: ['/path/to/your-backup-script'], timeout_seconds: 7200 },
      'restore-test': { type: 'restore_test' },
    },
    locations: { 'nas-main': '/path/to/backups' },
  }, null, 2);
  const overlay = h('div', { class: 'overlay' }, h('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true', style: 'max-width:760px' },
    h('h2', {}, `Install the agent on ${server.hostname}`),
    h('p', {}, 'Run one of these on that server, as root or Administrator. It downloads the agent, checks it can reach BMS, installs it as a service that starts at boot, and starts it. The secret is shown only once. If you lose it, remove the agent and enroll again.'),
    codeBlock('Linux (needs Python 3 and systemd)', r.install.linux),
    codeBlock('Windows (elevated PowerShell, needs Python 3 installed for all users)', r.install.windows),
    h('p', { class: 'small muted' }, `Replace the folder after each location key with where that location is on this server. Add --backup-script PATH to let BMS start your backup. Agent version ${r.agent_release.version ?? '?'}, SHA-256 ${(r.agent_release.sha256 ?? '').slice(0, 16)}…`),
    h('details', {}, h('summary', {}, 'Or set it up by hand'), codeBlock('config.json', config)),
    h('div', { class: 'actions' }, h('button', { class: 'primary', onclick: () => { overlay.remove(); show(); } }, 'I have copied it'))));
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

// --- locations ---
async function locations() {
  const r = await api('GET', '/backup-locations');
  return h('div', {}, topbar('Locations', 'Where BMS-managed backups (rsync, robocopy, manual copies) are written. Restore tests read from here. Platform backups such as Acronis use the locations set up on the platform, not these.', isAdmin() && h('button', { class: 'primary', onclick: () => locationForm() }, 'Add location')),
    card(null, table([
      { title: 'Name', render: (l) => l.name }, { title: 'Key', render: (l) => h('code', {}, l.loc_key) }, { title: 'Type', render: (l) => label(l.kind) },
      { title: 'Path on the BMS server', render: (l) => (l.path ? h('code', {}, l.path) : h('span', { class: 'muted' }, 'Agent only')) },
      { title: 'Jobs', render: (l) => l.jobs },
      { title: '', render: (l) => isAdmin() && h('span', { class: 'row' },
        h('button', { class: 'link', onclick: () => locationForm(l) }, 'Edit'),
        h('button', { class: 'link', onclick: async () => { if (await confirmModal('Delete location?', l.name, 'Delete') && await act(() => api('DELETE', `/backup-locations/${l.id}`), 'Location deleted')) show(); } }, 'Delete')) },
    ], r.data, { empty: 'No locations yet. Add the places your backups are written to.' })));
}
async function locationForm(existing) {
  const done = await formModal({
    title: existing ? `Edit ${existing.name}` : 'Add backup location', submitLabel: 'Save',
    fields: [
      { name: 'name', label: 'Name', required: true, value: existing?.name ?? '' },
      { name: 'loc_key', label: 'Key', required: true, value: existing?.loc_key ?? '', hint: 'Short and lowercase, e.g. nas-main. Agents map this key to a folder on their own machine. It cannot change once jobs use it.' },
      { name: 'kind', label: 'Type', type: 'select', options: opts(LOCATION_KINDS), value: existing?.kind ?? 'other' },
      { name: 'path', label: 'Path on the BMS server', value: existing?.path ?? '', hint: 'Only if the BMS server itself can read this location (a local folder or a mounted share). Leave empty if only the server’s agent can reach it.' },
      { name: 'notes', label: 'Notes', type: 'textarea', value: existing?.notes ?? '' },
    ],
    onSubmit: (v) => api(existing ? 'PATCH' : 'POST', existing ? `/backup-locations/${existing.id}` : '/backup-locations', { name: v.name.trim(), loc_key: v.loc_key.trim(), kind: v.kind, path: v.path.trim() || null, notes: v.notes.trim() || null }),
  });
  if (done) { toast('Location saved'); show(); }
}

// --- backups ---
async function backups() {
  const [jobs, srv] = await Promise.all([api('GET', '/backup-jobs?limit=200'), api('GET', '/servers?limit=500')]);
  const addBtn = isAdmin() && h('button', { class: 'primary', onclick: () => jobForm(srv.data) }, 'New backup job');
  return h('div', {}, topbar('Backups', null, addBtn), card(null, table([
    { title: 'Server', render: (j) => j.hostname }, { title: 'Method', render: (j) => label(j.backup_method) },
    { title: 'Backs up', render: (j) => scopeCell(j) },
    { title: 'Location', render: (j) => j.location_name ? (j.destination_path ? `${j.location_name} / ${j.destination_path}` : j.location_name) : (LOCATION_METHODS.includes(j.backup_method) ? h('span', { class: 'muted' }, 'None set') : h('span', { class: 'muted', title: 'Platform backups use the locations configured on the platform.' }, 'On the platform')) },
    { title: 'Schedule', render: (j) => (j.schedule ? h('code', {}, j.schedule) : '—') }, { title: 'Status', render: (j) => badge(j.status) },
    { title: 'Size', render: (j) => (j.size_gb != null ? `${j.size_gb} GB` : '—') }, { title: 'Last run', render: (j) => fmtAgo(j.completed_time) },
    { title: 'Tested', render: (j) => (j.test_status ? badge(j.test_status) : '—') },
    { title: '', render: (j) => h('span', { class: 'row' },
      isAdmin() && h('button', { class: 'link', onclick: async (e) => { e.stopPropagation(); if (await act(() => api('POST', `/backup-jobs/${j.id}/run`), 'Backup started. The agent picks it up within a minute.')) show(); } }, 'Run now'),
      canRunTests() && h('button', { class: 'link', onclick: (e) => { e.stopPropagation(); runTest(j); } }, 'Run test'),
      isAdmin() && LOCATION_METHODS.includes(j.backup_method) && h('button', { class: 'link', onclick: (e) => { e.stopPropagation(); jobLocation(j); } }, 'Location'),
      isAdmin() && h('button', { class: 'link', onclick: (e) => { e.stopPropagation(); jobScope(j); } }, 'Scope'),
      isAdmin() && h('button', { class: 'link', onclick: (e) => { e.stopPropagation(); setStatus(j); } }, 'Update status'),
      isAdmin() && h('button', { class: 'link', onclick: async (e) => { e.stopPropagation(); if (await confirmModal('Delete backup job?', `${j.hostname} / ${label(j.backup_method)}`, 'Delete') && await act(() => api('DELETE', `/backup-jobs/${j.id}`), 'Job deleted')) show(); } }, 'Delete')) },
  ], jobs.data, { empty: 'No backup jobs yet.' })));
}
const scopeCell = (j) => {
  if (!j.scope_type) return h('span', { class: 'muted' }, 'Not stated');
  const ex = j.excluded_paths ?? [];
  const what = j.scope_type === 'full_server' ? 'Full server' : `${j.source_paths.length} folder${j.source_paths.length === 1 ? '' : 's'}: ${j.source_paths.slice(0, 2).join(', ')}${j.source_paths.length > 2 ? '…' : ''}`;
  const title = [j.scope_type === 'folders' ? `Backs up:\n${j.source_paths.join('\n')}` : 'Backs up the whole server', ...(ex.length ? [`Excludes:\n${ex.join('\n')}`] : [])].join('\n\n');
  return h('span', { title }, what, ex.length ? ` · ${ex.length} excluded` : '');
};
const scopeFields = (j = {}) => [
  { name: 'scope_type', label: 'What it backs up', type: 'select', options: [{ value: '', label: 'Not stated' }, { value: 'full_server', label: 'Full server' }, { value: 'folders', label: 'Specific folders' }], value: j.scope_type ?? '' },
  { name: 'excluded_paths', label: 'Leave out these folders (one per line)', type: 'textarea', value: (j.excluded_paths ?? []).join('\n'), hint: 'Inside the folders above, e.g. C:\\Folder\\Sub1 when C:\\Folder is backed up. For a full server, any folder. Recorded and reported, not enforced on the backup tool.' },
  { name: 'layout', label: 'How it is laid out in the destination', type: 'select', options: LAYOUTS, value: j.layout ?? '', hint: 'Tells BMS how the backup tool places the source in the destination (e.g. robocopy of one folder, or rsync with a folder per source), so a restore test can find a backed-up file’s source on the server and compare the two. Needs an agent on the source server that allows those folders.' },
  { name: 'source_paths', label: 'Folders (one per line)', type: 'textarea', value: (j.source_paths ?? []).join('\n'), hint: 'Only for specific folders, e.g. /var/www or D:\\Data. BMS records and reports this, it does not change what the backup tool does.' },
];
const splitLines = (text) => text.split('\n').map((x) => x.trim()).filter(Boolean);
const LAYOUTS = [
  { value: '', label: 'Not stated' },
  { value: 'contents', label: 'Contents of the one source folder, directly in the destination' },
  { value: 'by_folder_name', label: 'One sub-folder per source folder, named after it' },
  { value: 'full_path', label: 'The full original path recreated in the destination' },
];
const scopeBody = (v) => ({ layout: v.layout || null, scope_type: v.scope_type || null, excluded_paths: splitLines(v.excluded_paths), source_paths: v.scope_type === 'folders' ? v.source_paths.split('\n').map((x) => x.trim()).filter(Boolean) : [] });
const locationOptions = (list) => [{ value: '', label: 'None' }, ...list.map((l) => ({ value: String(l.id), label: `${l.name} (${l.loc_key})` }))];
async function jobLocation(j) {
  const list = (await api('GET', '/backup-locations')).data;
  if (!list.length) return toast('Add a location first, on the Locations page.', true);
  const done = await formModal({
    title: `Location for ${j.hostname} / ${label(j.backup_method)}`, submitLabel: 'Save',
    fields: [
      { name: 'location_id', label: 'Backup location', type: 'select', options: locationOptions(list), value: j.location_id ? String(j.location_id) : '', hint: 'Where this backup is written. Restore tests read from here.' },
      { name: 'destination_path', label: 'Folder inside the location', value: j.destination_path ?? '', hint: 'Relative, e.g. web-01/daily. Leave empty to use the location itself.' },
    ],
    onSubmit: (v) => api('PATCH', `/backup-jobs/${j.id}`, { location_id: v.location_id ? Number(v.location_id) : null, destination_path: v.location_id && v.destination_path.trim() ? v.destination_path.trim() : null }),
  });
  if (done) { toast('Location saved'); show(); }
}
async function jobForm(serverList) {
  if (!serverList.length) return toast('Add a server first.', true);
  const locs = (await api('GET', '/backup-locations')).data;
  const done = await formModal({
    title: 'New backup job',
    fields: [
      { name: 'server_id', label: 'Server', type: 'select', options: serverList.map((s) => ({ value: s.id, label: s.hostname })) },
      { name: 'backup_method', label: 'Method', type: 'select', options: opts(METHODS), hint: 'The server must have this method enabled.' },
      { name: 'location_id', label: 'Backup location', type: 'select', options: locationOptions(locs), hint: 'For rsync, robocopy and manual copies. Leave as None for Acronis and other platforms: they use the locations configured on the platform.' },
      { name: 'destination_path', label: 'Folder inside the location', hint: 'Where this job’s data lands, relative to the location, e.g. web-01/daily. Restore tests read exactly this folder. Needs a location.' },
      ...scopeFields(),
      { name: 'schedule', label: 'Schedule (cron)', value: '0 2 * * *', hint: 'minute hour day month weekday, e.g. 0 2 * * * = 2:00 daily' },
      { name: 'retention_days', label: 'Retention (days)', type: 'number', value: '90' },
    ],
    onSubmit: (v) => api('POST', '/backup-jobs', { server_id: Number(v.server_id), backup_method: v.backup_method, location_id: v.location_id ? Number(v.location_id) : undefined, destination_path: v.destination_path.trim() || undefined, ...scopeBody(v), schedule: v.schedule.trim() || undefined, retention_days: v.retention_days ? Number(v.retention_days) : undefined }),
  });
  if (done) { toast('Backup job created'); show(); }
}
async function jobScope(j) {
  const done = await formModal({
    title: `What ${j.hostname} / ${label(j.backup_method)} backs up`, submitLabel: 'Save', fields: scopeFields(j),
    onSubmit: (v) => api('PATCH', `/backup-jobs/${j.id}`, scopeBody(v)),
  });
  if (done) { toast('Scope saved'); show(); }
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
      { title: 'Hash match', render: (x) => (x.hash_match == null ? '—' : x.hash_match ? 'Yes' : 'No') },
      { title: 'Compared with source', render: (x) => (x.source_status === 'verified' ? badge('success', 'Matches')
        : x.source_status === 'mismatch' ? badge('failed', 'Differs')
          : x.source_status === 'not_comparable' ? h('span', { class: 'muted', title: x.source_note ?? '' }, 'Not compared') : '—') }, { title: 'Duration', render: (x) => (x.duration_seconds != null ? `${x.duration_seconds}s` : '—') },
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

// --- integrations (admin) ---
async function integrations() {
  const r = await api('GET', '/integrations');
  const sync = (i) => async () => {
    const res = await api('POST', `/integrations/${i.id}/sync`).catch((e) => ({ success: false, data: { error: errMessage(e) } }));
    toast(res.success ? 'Synced' : `Sync failed: ${res.data.error}`, !res.success);
    show();
  };
  return h('div', {}, topbar('Integrations', 'Connections to external backup platforms. BMS reads backup status for servers you link to a device, and never changes anything on the platform.', h('button', { class: 'primary', onclick: integrationForm }, 'Add Acronis connection')),
    card(null, table([
      { title: 'Name', render: (i) => i.name }, { title: 'Platform', render: (i) => label(i.platform) }, { title: 'Datacenter', render: (i) => i.base_url.replace('https://', '') },
      { title: 'Client', render: (i) => h('code', {}, i.client_id_hint ?? '—') },
      { title: 'Linked servers', render: (i) => h('span', {}, String(i.links), i.auto_import ? h('div', { class: 'small' }, `Auto-import on (${label(i.auto_import_os)})`) : '') },
      { title: 'Last sync', render: (i) => (i.last_sync_status === 'error'
        ? h('span', {}, badge('failed', 'Error'), h('div', { class: 'small' }, i.last_sync_error))
        : i.last_sync_at ? h('span', {}, badge('success', 'OK'), ` ${fmtAgo(i.last_sync_at)}`) : 'Not yet') },
      { title: '', render: (i) => h('span', { class: 'row' },
        h('button', { class: 'link', onclick: async () => { if (await act(() => api('POST', `/integrations/${i.id}/test`))) toast('Connection works'); } }, 'Test'),
        h('button', { class: 'link', onclick: sync(i) }, 'Sync now'),
        h('button', { class: 'link', onclick: () => importDevices(i) }, 'Import devices'),
        h('button', { class: 'link', onclick: () => autoImportForm(i) }, 'Auto-import'),
        h('button', { class: 'link', onclick: async () => { if (await confirmModal('Remove connection?', `${i.name} and the links from servers to its devices are removed. Backup history stays.`, 'Remove') && await act(() => api('DELETE', `/integrations/${i.id}`), 'Connection removed')) show(); } }, 'Remove')) },
    ], r.data, { empty: 'No connections yet. Add one to read backup status from Acronis.' })));
}
// Create BMS servers from the devices on a connection, so they do not have to be added and linked one by one.
async function importDevices(i) {
  let devices;
  try { devices = (await api('GET', `/integrations/${i.id}/devices`)).data; } catch (e) { return toast(errMessage(e), true); }
  const open = devices.filter((d) => !d.linked_server_id);
  if (!open.length) return toast('Every device on this connection is already linked to a server.', true);
  let result = null;
  const done = await formModal({
    title: `Import devices from ${i.name}`, submitLabel: 'Import selected',
    fields: [
      { name: 'ids', label: `Devices (${open.length} not yet linked)`, type: 'checks', value: open.filter((d) => d.protected).map((d) => d.id),
        options: open.map((d) => ({ value: d.id, label: `${d.name} · ${d.status ? label(d.status) : 'no status'} · ${d.plans.length ? d.plans.join(', ') : 'no plan'}${d.matches_server_id ? ' · links to the existing server of this name' : ''}${d.suggested_hostname ? '' : ' · name unusable'}` })),
        hint: 'Devices with a protection plan are pre-selected. BMS reads plans, not licences, so check the list against your licensing.' },
      { name: 'os_type', label: 'Operating system for new servers', type: 'select', options: [{ value: 'windows', label: 'Windows' }, { value: 'linux', label: 'Linux' }], hint: 'Applies to servers created now. Change any of them later by editing the server.' },
      { name: 'link_existing', label: 'If a server with the same name exists', type: 'select', options: [{ value: 'true', label: 'Link it to the device' }, { value: 'false', label: 'Skip the device' }] },
    ],
    onSubmit: async (v) => {
      if (!v.ids.length) throw new Error('Pick at least one device');
      result = (await api('POST', `/integrations/${i.id}/import`, { external_ids: v.ids, os_type: v.os_type, link_existing: v.link_existing === 'true' })).data;
    },
  });
  if (!done || !result) return;
  const skipped = result.results.filter((r) => r.outcome === 'skipped').slice(0, 5).map((r) => `${r.name ?? r.external_id}: ${r.reason}`);
  await confirmModal('Import finished', `Created ${result.created} server(s), linked ${result.linked_existing} existing, skipped ${result.skipped}.${skipped.length ? ` ${skipped.join(' ')}` : ''}`, 'Done');
  show();
}
// Hands-off population: while on, every sync adds devices that have a protection plan as servers.
async function autoImportForm(i) {
  const done = await formModal({
    title: `Auto-import from ${i.name}`, submitLabel: 'Save',
    fields: [
      { name: 'auto_import', label: 'Add new devices as servers automatically', type: 'select', options: [{ value: 'false', label: 'Off' }, { value: 'true', label: 'On' }], value: String(i.auto_import),
        hint: 'When on, each sync (every 15 minutes) adds devices that have a protection plan and are not in BMS yet. At most 25 per sync, within your subscription limit. Existing servers are never touched, and admins are notified of what was added.' },
      { name: 'auto_import_os', label: 'Operating system for servers it creates', type: 'select', options: [{ value: 'windows', label: 'Windows' }, { value: 'linux', label: 'Linux' }], value: i.auto_import_os, hint: 'Acronis data does not say, so all of them get this. Edit a server afterwards if it differs.' },
    ],
    onSubmit: (v) => api('PATCH', `/integrations/${i.id}`, { auto_import: v.auto_import === 'true', auto_import_os: v.auto_import_os }),
  });
  if (done) { toast('Auto-import saved'); show(); }
}
async function integrationForm() {
  const done = await formModal({
    title: 'Add Acronis connection', submitLabel: 'Connect',
    fields: [
      { name: 'name', label: 'Name', required: true, value: 'Acronis' },
      { name: 'base_url', label: 'Datacenter URL', required: true, hint: 'The address of your Acronis console, e.g. https://eu2-cloud.acronis.com' },
      { name: 'client_id', label: 'API client ID', required: true },
      { name: 'client_secret', label: 'API client secret', type: 'password', required: true, hint: 'Create an API client in the Acronis console with a read-only role. The secret is stored encrypted and never shown again.' },
      { name: 'stale_after_hours', label: 'Warn when no successful backup for (hours)', type: 'number', value: '36' },
    ],
    onSubmit: (v) => api('POST', '/integrations', { platform: 'acronis', name: v.name.trim(), base_url: v.base_url.trim(), client_id: v.client_id.trim(), client_secret: v.client_secret, stale_after_hours: Number(v.stale_after_hours) || 36 }),
  });
  if (done) { toast('Connected. Now link servers to their devices from each server.'); show(); }
}

// --- companies (platform admins only) ---
async function companies() {
  const r = await api('GET', '/platform/companies');
  return h('div', {}, topbar('Companies', 'Every company on this system. Open one to work inside it as its admin. Each visit is recorded in that company’s audit log.', h('button', { class: 'primary', onclick: companyForm }, 'Create company')),
    card(null, table([
      { title: 'Company', render: (c) => c.name }, { title: 'Slug', render: (c) => c.slug }, { title: 'Plan', render: (c) => label(c.subscription_tier) },
      { title: 'Users', render: (c) => c.users }, { title: 'Servers', render: (c) => `${c.servers}${c.max_servers ? ` / ${c.max_servers}` : ''}` },
      { title: 'Status', render: (c) => badge(c.is_active ? 'success' : 'failed', c.is_active ? 'Active' : 'Disabled') }, { title: 'Created', render: (c) => fmtDate(c.created_at) },
      { title: '', render: (c) => (c.id === me.company.id ? h('span', { class: 'small muted' }, me.acting ? 'Open now' : 'Your company') : h('button', { class: 'link', onclick: () => enterCompany(c) }, 'Open')) },
    ], r.data)));
}
async function enterCompany(c) {
  try { await api('POST', `/platform/companies/${c.id}/enter`); } catch (e) { return toast(errMessage(e), true); }
  setActing(c.id);
  history.replaceState({}, '', '/');
  location.reload();
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
    me = { user: { username: r.username, email: r.email }, company: { id: r.company_id, company_name: r.company_name, company_slug: r.company_slug }, role: r.role, platform: r.platform_admin, acting: r.acting_in_other_company };
    show();
  } catch { loginScreen(); }
})();
