import { api, getActing, setActing, h, setUnauthorizedHandler, setMfaRequiredHandler, toast, errMessage, badge, table, label, fmtAgo, fmtDate, formModal, confirmModal } from './lib.js';

const METHODS = ['rsync', 'robocopy', 'windows_manual_copy', 'linux_manual_copy', 'manual_copy', 'acronis', 'veeam', 'commvault', 'acronis_m365', 'microsoft_native_365', 'aws_backup', 'azure_backup'];
// BMS-managed copies go to a backup location. Every other method belongs to a platform that has its own locations.
const LOCATION_METHODS = ['rsync', 'robocopy', 'windows_manual_copy', 'linux_manual_copy', 'manual_copy'];
const LOCATION_KINDS = ['local_disk', 'network_share', 'nas', 'external_drive', 'offsite', 'other'];
const opts = (list) => list.map((v) => ({ value: v, label: label(v) }));
// The kind of backup, as people call it. Shown next to a server wherever tests are listed, because one server can have several.
const METHOD_NAMES = {
  rsync: 'Rsync', robocopy: 'Robocopy', windows_manual_copy: 'Windows manual copy', linux_manual_copy: 'Linux manual copy', manual_copy: 'Manual copy',
  acronis: 'Acronis', acronis_m365: 'Acronis Microsoft 365', microsoft_native_365: 'Microsoft 365 (native)', veeam: 'Veeam', commvault: 'Commvault', aws_backup: 'AWS Backup', azure_backup: 'Azure Backup',
};
const methodName = (m) => METHOD_NAMES[m] ?? label(m);
const serverCell = (x) => h('span', {}, x.hostname, x.backup_method ? h('span', { class: 'small muted' }, ` \u00b7 ${methodName(x.backup_method)}`) : '');
const ROLE_LABEL = { company_admin: 'Admin', company_manager: 'Manager', company_technician: 'Technician' };

let me = null; // { user, company, role }
const isAdmin = () => me.role === 'company_admin';
const canRunTests = () => me.role !== 'company_manager';

const PAGES = [
  { id: 'dashboard', group: 'top', title: 'Dashboard', render: dashboard },
  { id: 'servers', group: 'infrastructure', title: 'Servers', render: servers },
  { id: 'agents', group: 'infrastructure', title: 'Agents', render: agents },
  { id: 'backups', group: 'protection', title: 'Backups', render: backups },
  { id: 'locations', group: 'infrastructure', title: 'Locations', render: locations },
  { id: 'tests', group: 'protection', title: 'Tests', render: tests },
  { id: 'reports', group: 'protection', title: 'Reports', render: reports, noTechnician: true },
  { id: 'users', group: 'admin', title: 'Users', render: users, admin: true },
  { id: 'integrations', group: 'infrastructure', title: 'Integrations', render: integrations, admin: true },
  { id: 'overview', group: 'platform', title: 'All companies', render: overview, platform: true },
  { id: 'companies', group: 'platform', title: 'Companies', render: companies, platform: true },
  { id: 'people', group: 'platform', title: 'People', render: people, platform: true },
  { id: 'reminders', group: 'admin', title: 'Mail reminders', render: reminders, admin: true },
  { id: 'audit', group: 'admin', title: 'Audit log', render: auditLog, admin: true },
  { id: 'profile', group: 'account', title: 'My profile', render: profile },
];

// The sidebar, in groups: what you look at most first, account items at the bottom. An empty group (nothing the person may see) is left out.
const NAV_GROUPS = [['top', null], ['protection', 'Protection'], ['infrastructure', 'Infrastructure'], ['admin', 'Administration'], ['platform', 'Platform']];

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

// For people who were granted access to other companies: a picker for the company they are working in. (Platform admins
// use the Companies page instead, because they can reach every company.)
function companySwitcher() {
  if (me.platform || !(me.access ?? []).length) return null;
  const home = me.home ?? { id: me.company.id, name: me.company.company_name };
  const current = getActing() ? Number(getActing()) : home.id;
  const select = h('select', { 'aria-label': 'Company', onchange: async (e) => {
    const id = Number(e.target.value);
    if (id === home.id) { setActing(null); } else {
      try { await api('POST', `/me/companies/${id}/enter`); } catch (err) { e.target.value = String(current); return toast(errMessage(err), true); }
      setActing(id);
    }
    history.replaceState({}, '', '/');
    location.reload();
  } }, [{ id: home.id, name: `${home.name} (my company)` }, ...me.access].map((c) => h('option', { value: String(c.id), selected: c.id === current }, c.name)));
  return h('div', { class: 'field', style: 'margin:8px 0' }, h('label', { class: 'small muted' }, 'Company'), select);
}

function sidebar(page) {
  const navLink = (p) => h('a', { class: `nav${p.id === page.id ? ' active' : ''}`, href: `/companies/${me.company.company_slug}/${p.id}`, 'aria-current': p.id === page.id ? 'page' : false,
    onclick: (e) => { e.preventDefault(); go(p.id); } }, p.title);
  return h('nav', { class: 'side', 'aria-label': 'Main' },
    h('div', { class: 'brand' }, h('strong', {}, me.company.company_name), h('span', {}, `${ROLE_LABEL[me.role]} · ${me.user.username}`)),
    me.acting && h('div', { class: 'error', role: 'status', style: 'margin:8px 0' }, `Working inside ${me.company.company_name} ${me.platform ? 'as a platform admin' : `with the access you were granted (${ROLE_LABEL[me.role]})`}. Your changes are recorded in its audit log. `,
      h('button', { class: 'link', onclick: () => { setActing(null); location.assign('/'); } }, 'Back to my company')),
    companySwitcher(),
    NAV_GROUPS.map(([key, title]) => {
      const pages = PAGES.filter((p) => p.group === key && visible(p));
      return pages.length ? [title && h('div', { class: 'nav-group' }, title), pages.map(navLink)] : null;
    }),
    h('div', { class: 'spacer' }),
    h('div', { class: 'nav-group' }, 'Account'),
    PAGES.filter((p) => p.group === 'account' && visible(p)).map(navLink),
    h('a', { class: 'nav', href: '#', id: 'bell', onclick: (e) => { e.preventDefault(); notifications(); } }, 'Notifications', h('span', { class: 'badge info', id: 'bell-count', hidden: true }, '0')),
    h('button', { onclick: logout, style: 'margin-top:8px' }, 'Log out'));
}

// --- live pages ---
// Every few seconds the current page is rebuilt in the background and swapped in only if something changed, so statuses move
// without a reload. It never interrupts: nothing happens while a dialog is open, a field has been edited, a section is
// expanded, text is selected, or the tab is hidden. A failed refresh keeps what is on screen.
const LIVE_SECONDS = 15;
let liveBusy = false;
let liveStamp = null;

function liveIndicator(ok) {
  if (!liveStamp) {
    liveStamp = h('div', { class: 'live', role: 'status', 'aria-live': 'off', style: 'position:fixed;right:12px;bottom:8px;font-size:11px;opacity:.6;pointer-events:none' });
    document.body.append(liveStamp);
  }
  liveStamp.textContent = ok ? `Live · updated ${new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}` : 'Live · connection lost, retrying';
}

// True while the person could lose something by the page being rebuilt.
function liveShouldWait(view) {
  if (document.visibilityState !== 'visible' || document.querySelector('.overlay')) return true;
  if (view.querySelector('details[open]')) return true;
  const sel = window.getSelection();
  if (sel && !sel.isCollapsed && view.contains(sel.anchorNode)) return true;
  for (const el of view.querySelectorAll('input, textarea, select')) {
    if (el === document.activeElement) return true;
    if (el.tagName === 'SELECT') { if ([...el.options].some((o) => o.selected !== o.defaultSelected)) return true; }
    else if (el.type === 'checkbox' || el.type === 'radio') { if (el.checked !== el.defaultChecked) return true; }
    else if (el.value !== el.defaultValue) return true;
  }
  return false;
}

async function liveRefresh() {
  const view = document.getElementById('view');
  if (!me || !view || liveBusy || liveShouldWait(view)) return;
  liveBusy = true;
  const page = currentPage();
  try {
    const next = await page.render();
    // Do not apply a result that arrived after the person moved on, opened a dialog or started typing.
    if (currentPage().id !== page.id || document.getElementById('view') !== view || liveShouldWait(view)) return;
    if (next.innerHTML !== view.firstElementChild?.innerHTML || view.children.length !== 1) {
      const y = window.scrollY;
      view.replaceChildren(next);
      window.scrollTo(0, y);
    }
    liveIndicator(true);
    refreshUnread();
  } catch { liveIndicator(false); }
  finally { liveBusy = false; }
}
setInterval(liveRefresh, LIVE_SECONDS * 1000);
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') liveRefresh(); });

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
      if (r.mfa_required) { mfaCodeScreen(r.mfa_token); return; }
      await finishLogin(r);
    } catch (ex) { err.hidden = false; err.textContent = ex.message; btn.disabled = false; pass.value = ''; pass.focus(); }
  } },
  err, h('div', { class: 'field' }, h('label', { for: 'u' }, 'Username'), user), h('div', { class: 'field' }, h('label', { for: 'p' }, 'Password'), pass), btn);
  root.replaceChildren(h('div', { class: 'login' }, h('div', { class: 'card' }, h('h1', {}, 'Backup Management'), h('p', { class: 'muted' }, 'Log in to your company account'), form)));
  user.focus();
}

// --- two-step sign-in ---
// After the password, a code from the authenticator app (or, if the phone is lost, a one-time recovery code).
function mfaCodeScreen(mfaToken) {
  let recovery = false;
  const err = h('div', { class: 'error', hidden: true, role: 'alert' });
  const input = h('input', { id: 'mfa-code', inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: '7', required: true, placeholder: '123456' });
  const labelEl = h('label', { for: 'mfa-code' }, 'Code from your authenticator app');
  const toggle = h('button', { type: 'button', class: 'link' }, 'Lost your phone? Use a recovery code');
  const btn = h('button', { class: 'primary', type: 'submit', style: 'width:100%' }, 'Verify');
  toggle.onclick = () => {
    recovery = !recovery;
    input.value = ''; input.maxLength = recovery ? 30 : 7; input.placeholder = recovery ? 'xxxxx-xxxxx' : '123456';
    input.setAttribute('inputmode', recovery ? 'text' : 'numeric');
    labelEl.textContent = recovery ? 'One-time recovery code' : 'Code from your authenticator app';
    toggle.textContent = recovery ? 'Use the authenticator app instead' : 'Lost your phone? Use a recovery code';
    input.focus();
  };
  const form = h('form', { onsubmit: async (e) => {
    e.preventDefault(); btn.disabled = true; err.hidden = true;
    try {
      const r = await api('POST', '/auth/mfa/verify', { mfa_token: mfaToken, ...(recovery ? { recovery_code: input.value.trim() } : { code: input.value.trim() }) });
      await finishLogin(r);
    } catch (ex) {
      if (ex.body?.error_code === 'MFA_001') { loginScreen(); toast(ex.message, true); return; }
      err.hidden = false; err.textContent = ex.message; btn.disabled = false; input.value = ''; input.focus();
    }
  } }, err, h('div', { class: 'field' }, labelEl, input), btn, h('div', { style: 'margin-top:10px;display:flex;justify-content:space-between' }, toggle,
    h('button', { type: 'button', class: 'link', onclick: loginScreen }, 'Back')));
  root.replaceChildren(h('div', { class: 'login' }, h('div', { class: 'card' }, h('h1', {}, 'Two-step sign-in'), h('p', { class: 'muted' }, 'Enter the 6-digit code from your authenticator app.'), form)));
  input.focus();
}

// The same steps whether they fill the screen (setup is required) or sit in a dialog (changing the app from the profile).
//   1. scan the QR code (or type the secret)   2. prove it with a code   3. keep the recovery codes
async function mfaSetupFlow(container, { proof = null, onDone }) {
  const box = h('div', {});
  container.replaceChildren(box);
  const fail = (e) => toast(errMessage(e), true);
  let setup;
  try { setup = (await api('POST', '/me/mfa/setup', proof ?? undefined)).data; } catch (e) { fail(e); return false; }
  const grouped = setup.secret.match(/.{1,4}/g).join(' ');
  const err = h('div', { class: 'error', hidden: true, role: 'alert' });
  const code = h('input', { id: 'mfa-setup-code', inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: '7', placeholder: '123456', required: true });
  const btn = h('button', { class: 'primary', type: 'submit' }, 'Turn on');
  const qr = h('img', { alt: 'QR code for your authenticator app', width: '200', height: '200', src: `data:image/svg+xml;utf8,${encodeURIComponent(setup.qr_svg)}`, style: 'background:#fff;border-radius:6px;padding:6px' });
  box.append(
    h('ol', { style: 'padding-left:18px;line-height:1.6' },
      h('li', {}, 'Install an authenticator app on your phone (Microsoft Authenticator, Google Authenticator, Authy or similar).'),
      h('li', {}, 'In the app, add an account and scan this code:')),
    h('div', { style: 'display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin:8px 0' }, qr,
      h('div', {}, h('div', { class: 'small muted' }, 'Cannot scan? Type this key into the app instead:'), h('code', { style: 'word-break:break-all;user-select:all' }, grouped))),
    h('form', { onsubmit: async (e) => {
      e.preventDefault(); btn.disabled = true; err.hidden = true;
      try {
        const r = await api('POST', '/me/mfa/enable', { code: code.value.trim() });
        showRecoveryCodes(box, r.recovery_codes, onDone);
      } catch (ex) { err.hidden = false; err.textContent = ex.message; btn.disabled = false; code.value = ''; code.focus(); }
    } }, err, h('div', { class: 'field' }, h('label', { for: 'mfa-setup-code' }, 'Then enter the 6-digit code the app shows'), code), btn));
  code.focus();
  return true;
}

function showRecoveryCodes(box, codes, onDone) {
  const text = codes.join('\n');
  const saved = h('input', { type: 'checkbox', id: 'mfa-saved' });
  const cont = h('button', { class: 'primary', disabled: true, onclick: onDone }, 'Continue');
  saved.onchange = () => { cont.disabled = !saved.checked; };
  box.replaceChildren(
    h('h3', {}, 'Save your recovery codes'),
    h('p', {}, 'If you lose your phone, each of these signs you in once. Keep them somewhere safe and private. They are shown only now.'),
    h('pre', { style: 'columns:2;background:rgba(127,127,127,.15);padding:12px;border-radius:6px;user-select:all' }, text),
    h('div', { class: 'row' },
      h('button', { onclick: () => copyText(text) }, 'Copy'),
      h('button', { onclick: () => { const a = h('a', { href: `data:text/plain;charset=utf-8,${encodeURIComponent(`BMS recovery codes for ${me.user.username}\n\n${text}\n`)}`, download: 'bms-recovery-codes.txt' }); document.body.append(a); a.click(); a.remove(); } }, 'Download')),
    h('label', { style: 'display:block;margin:12px 0' }, saved, ' I have saved these codes'),
    cont);
}

// When two-step sign-in is required and not set up yet, this is the only thing the person can do.
function mfaSetupScreen() {
  const holder = h('div', {});
  root.replaceChildren(h('div', { class: 'login' }, h('div', { class: 'card', style: 'max-width:520px' },
    h('h1', {}, 'Set up two-step sign-in'),
    h('p', { class: 'muted' }, 'Your organisation requires a code from an authenticator app when you sign in. Set it up now to continue.'),
    holder, h('div', { style: 'margin-top:14px' }, h('button', { class: 'link', onclick: logout }, 'Log out')))));
  mfaSetupFlow(holder, { onDone: async () => { await loadMe(); show(); } });
}

// Ask for the password and a current code before changing anything about two-step sign-in. Resolves with the proof.
const mfaProof = (title) => new Promise((resolve) => {
  let proof = null;
  formModal({
    title, submitLabel: 'Continue',
    fields: [{ name: 'password', label: 'Your password', type: 'password', required: true }, { name: 'code', label: 'Current code from your authenticator app', required: true }],
    onSubmit: async (v) => { proof = { password: v.password, code: v.code.trim() }; },
  }).then((ok) => resolve(ok ? proof : null));
});

async function changeAuthenticator() {
  const proof = await mfaProof('Change authenticator app');
  if (!proof) return;
  const overlay = h('div', { class: 'overlay' });
  const dialog = h('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true', style: 'max-width:560px' }, h('h2', {}, 'Change authenticator app'), h('div', {}));
  overlay.append(dialog);
  document.body.append(overlay);
  const ok = await mfaSetupFlow(dialog.lastChild, { proof, onDone: async () => { overlay.remove(); toast('Authenticator app changed'); await loadMe(); show(); } });
  if (!ok) overlay.remove();
}

async function newRecoveryCodes() {
  const proof = await mfaProof('New recovery codes');
  if (!proof) return;
  let r;
  try { r = await api('POST', '/me/mfa/recovery-codes', proof); } catch (e) { return toast(errMessage(e), true); }
  const overlay = h('div', { class: 'overlay' });
  const dialog = h('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true', style: 'max-width:560px' }, h('h2', {}, 'New recovery codes'), h('div', {}));
  overlay.append(dialog);
  document.body.append(overlay);
  showRecoveryCodes(dialog.lastChild, r.recovery_codes, async () => { overlay.remove(); await loadMe(); show(); });
}

async function turnOffMfa() {
  const proof = await mfaProof('Turn off two-step sign-in');
  if (proof && await act(() => api('POST', '/me/mfa/disable', proof), 'Two-step sign-in turned off')) { await loadMe(); show(); }
}

// Reload who I am (including two-step state and the companies I can switch into).
async function loadMe() {
  const r = (await api('GET', '/me')).data;
  me = {
    id: r.id, user: { username: r.username, email: r.email }, company: { id: r.company_id, company_name: r.company_name, company_slug: r.company_slug },
    role: r.role, platform: r.platform_admin, acting: r.acting_in_other_company, home: r.home_company, access: r.access ?? [], mfa: r.mfa,
  };
}

// After the password (and the code, if one was needed) the person is in.
async function finishLogin(r) {
  me = { id: r.user.id, user: r.user, company: r.company, role: r.role, platform: r.platform_admin, access: [], mfa: { setup_required: r.mfa_setup_required } };
  try { await loadMe(); } catch (e) { /* keep what the login gave us */ }
  if (me.mfa?.setup_required) mfaSetupScreen(); else show();
}

async function logout() { await api('POST', '/auth/logout').catch(() => {}); setActing(null); history.replaceState({}, '', '/'); loginScreen(); }
setMfaRequiredHandler(() => { if (me) mfaSetupScreen(); });
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
      metric('Backups (30d)', b.finished, '', `${b.success} ok · ${b.warning} warning · ${b.failed} failed${b.missed ? ` · ${b.missed} missed` : ''}`),
      metric('Tests passed (7d)', pct(d.tests.last_7d), '', `${d.tests.last_7d.passed} of ${d.tests.last_7d.total}`),
      metric('Open test failures', d.unresolved_failures, d.unresolved_failures ? 'bad' : 'ok', d.unresolved_failures ? 'Need review' : 'All clear'),
      metric('Backup problems to review', d.unresolved_backup_failures, d.unresolved_backup_failures ? 'bad' : 'ok', d.unresolved_backup_failures ? 'Need review' : 'All clear')),
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
  { name: 'hostname', label: 'Hostname', required: true, value: s.hostname, hint: 'For a Microsoft 365 tenant, a short name such as contoso-365.' },
  { name: 'ip_address', label: 'IP address', value: s.ip_address ?? '' },
  { name: 'os_type', label: 'Operating system', type: 'select', options: [{ value: 'linux', label: 'Linux' }, { value: 'windows', label: 'Windows' }, { value: 'microsoft_365', label: 'Microsoft 365 tenant (no machine)' }], value: s.os_type },
  { name: 'timezone', label: 'Time zone of the backup console', value: s.timezone ?? (Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'), hint: 'For example Africa/Johannesburg or UTC. Tests by hand pick a day in this zone and read the dates entered in it.' },
  { name: 'criticality', label: 'Criticality', type: 'select', options: opts(['standard', 'critical', 'non-critical']), value: s.criticality },
  { name: 'backup_methods', label: 'Backup methods', type: 'checks', options: opts(METHODS), value: s.backup_methods ?? [] },
  { name: 'data_function', label: 'What this server stores or does', required: true, value: s.data_function ?? '', hint: 'For example Finance database, HR file shares or Domain controller. An anonymised report shows this in place of the server\u2019s name, so do not put the name or the company in it.' },
  { name: 'retention_policy_days', label: 'Retention (days)', type: 'number', value: s.retention_policy_days ?? '', hint: 'Leave empty to keep indefinitely' },
  { name: 'notes', label: 'Notes', type: 'textarea', value: s.notes ?? '' },
  { name: 'test_targets', label: 'Test mailboxes or sites to sample (Microsoft 365 only)', type: 'textarea', value: (s.test_targets ?? []).join('\n'), hint: 'Optional, one per line. Leave it empty and BMS picks any mailbox, OneDrive or site Acronis has backed up (link the tenant to its Acronis connection first). Fill it in only to limit tests to these names. Each test by hand samples one, the one tested least recently. A tenant has no IP address or agent and can only use the Microsoft 365 backup methods.' },
  { name: 'reference_items', label: 'Reference items (Microsoft 365 only)', type: 'textarea', value: (s.reference_items ?? []).map((r) => `${r.label} | ${r.hash} | ${r.where ?? ''}`).join('\n'),
    hint: 'Known files kept in the test mailboxes, one per line: name | fingerprint | where it is. Work out each file\u2019s SHA-256 once when you create it. A tester then restores it and enters only the restored copy\u2019s fingerprint, and BMS compares it with this one, so nobody needs the original or the mailbox owner. The mailbox must be in the backup, and the item must exist in a recovery point.' },
];
const serverBody = (v) => ({
  hostname: v.hostname.trim(), ip_address: v.ip_address.trim() || null, os_type: v.os_type, timezone: v.timezone.trim() || 'UTC', criticality: v.criticality,
  backup_methods: v.backup_methods, retention_policy_days: v.retention_policy_days ? Number(v.retention_policy_days) : null, data_function: v.data_function.trim() || null, notes: v.notes.trim() || null,
  test_targets: v.os_type === 'microsoft_365' ? v.test_targets.split('\n').map((x) => x.trim()).filter(Boolean) : null,
  reference_items: v.os_type === 'microsoft_365' ? v.reference_items.split('\n').map((x) => x.trim()).filter(Boolean).map((line) => { const [label, hash, ...where] = line.split('|').map((p) => p.trim()); return { label, hash: hash ?? '', where: where.join(' | ') || null }; }) : null,
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
      row('Health', badge(full.status)), row('Type', full.os_type === 'microsoft_365' ? 'Microsoft 365 tenant' : label(full.os_type)), full.os_type !== 'microsoft_365' && row('IP', full.ip_address ?? '—'), row('Criticality', label(full.criticality)),
      row('Methods', full.backup_methods.map(label).join(', ') || '—'), row('Stores', full.data_function || '—'), row('Retention', full.retention_policy_days ? `${full.retention_policy_days} days` : 'Indefinite'),
      row('Last backup', `${label(full.last_backup_status)} · ${fmtDate(full.last_backup_time)}`), row('Last test', `${label(full.last_test_status)} · ${fmtDate(full.last_test_time)}`),
      row('Technicians', full.assigned_technicians.map((t) => t.username).join(', ') || 'None assigned'), full.os_type !== 'microsoft_365' && row('Agent', agentSummary(full.agent)), full.os_type === 'microsoft_365' && row('Test mailboxes', (full.test_targets ?? []).join(', ') || 'None listed: choose one each time'), full.os_type === 'microsoft_365' && row('Reference items', (full.reference_items ?? []).map((r) => r.label).join(', ') || 'None: tests check the item opens, with no original to compare'), row('Platforms', linksSummary(full.links)), (full.workloads ?? []).map((w) => workloadRow(row, w)), full.validation && full.links.length > 0 && row('Acronis validation', h('span', {}, h('strong', {}, full.validation.confirmed ? 'Plan confirmed' : 'No plan confirmed'), h('div', { class: 'small' }, full.validation.message))), row('Notes', full.notes ?? '—'))),
    h('div', { class: 'actions' },
      isAdmin() && h('button', { class: 'danger', onclick: async () => {
        if (await confirmModal('Delete server?', `${full.hostname} and its backup jobs will be removed from view. History is kept for audit.`, 'Delete')) {
          overlay.remove(); if (await act(() => api('DELETE', `/servers/${full.id}`), 'Server deleted')) show();
        } } }, 'Delete'),
      isAdmin() && full.os_type !== 'microsoft_365' && (full.agent.enrolled
        ? h('button', { onclick: async () => { if (await confirmModal('Remove agent?', `${full.hostname} will stop receiving backup and test commands. The agent software on the server keeps running until you stop it.`, 'Remove') && await act(() => api('DELETE', `/servers/${full.id}/agent`), 'Agent removed')) { overlay.remove(); show(); } } }, 'Remove agent')
        : h('button', { onclick: () => { overlay.remove(); enrollAgent(full); } }, 'Enroll agent')),
      canRunTests() && h('button', { onclick: () => { overlay.remove(); manualTestForm({ server_id: full.id, hostname: full.hostname, backup_method: full.os_type === 'microsoft_365' ? 'acronis_m365' : '',
        source: full.links.length > 0 ? `Backup status for this server is read from ${[...new Set(full.links.map((l) => `${l.integration.name} (${l.external_name ?? l.external_id})`))].join(', ')}. The restore itself is done by hand in the backup console.`
          : 'No backup connection is linked to this server, so BMS has no backup status for it. The test is done wholly by hand in the backup console, and needs no backup job.' }); } }, 'Test by hand'),
      isAdmin() && h('button', { onclick: () => { overlay.remove(); linkForm(full); } }, 'Link to platform device'),
      isAdmin() && full.links.length > 0 && h('button', { onclick: async () => {
        if (await confirmModal('Remove platform link?', 'BMS will stop reading backup status for this server from the platform. Past results stay.', 'Remove')
          && await act(async () => { for (const l of full.links) await api('DELETE', `/servers/${full.id}/links/${l.id}`); }, 'Link removed')) { overlay.remove(); show(); } } }, 'Remove platform link'),
      isAdmin() && h('button', { onclick: async () => { overlay.remove(); await assignForm(full, techs, assigned); } }, 'Assign technicians'),
      isAdmin() && h('button', { onclick: () => { overlay.remove(); serverForm(full); } }, 'Edit'),
      h('button', { class: 'primary', onclick: () => overlay.remove() }, 'Close'))));
  document.body.append(overlay);
}
const workloadRow = (row, w) => row(w.label, w.summary
  ? h('span', {}, h('strong', {}, label(w.summary.state)), ` · ${w.summary.items} items: ${w.summary.ok} ok, ${w.summary.warning} with warnings, ${w.summary.failed} failed${w.summary.cancelled ? `, ${w.summary.cancelled} cancelled` : ''}`,
    h('div', { class: 'small' }, `Last backup ${w.summary.last_run_at ? fmtDate(w.summary.last_run_at) : 'never'}, read from ${w.connection}${w.summary.catching_up ? '. Still catching up on Acronis activity, so counts may be incomplete' : ''}`),
    Object.keys(w.summary.errors).length > 0 && h('div', { class: 'small' }, `Errors: ${Object.entries(w.summary.errors).map(([c, n]) => `${c} (${n})`).join(', ')}`),
    w.problems.length > 0 && h('div', { class: 'small' }, 'Failed items: ', w.problems.map((p) => `${p.name}${p.error_code ? ` (${p.error_code})` : ''}`).join(', '))) : 'Nothing read from Acronis yet');

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
  // A Microsoft 365 tenant is linked to its backed-up workloads (any number of them); every other server to one device.
  const tenant = server.os_type === 'microsoft_365';
  const free = devices.filter((d) => !d.linked_server_id && Boolean(d.workload) === tenant);
  if (!free.length) return toast(tenant ? 'No Microsoft 365 backups to link on that connection yet. They appear once the connection has read them, or they are all linked already.' : 'Every device on that connection is already linked, or none were found.', true);
  if (tenant) {
    const doneTenant = await formModal({
      title: `Link ${server.hostname} to its Microsoft 365 backups`, submitLabel: 'Link',
      fields: [{ name: 'external_ids', label: 'Backups to read', type: 'checks', stacked: true, selectAll: true, options: free.map((d) => ({ value: d.id, label: `${d.name}${d.status ? ` (${label(d.status)})` : ''}` })), value: free.map((d) => d.id),
        hint: 'BMS reads how these backups are doing and shows one status for the tenant. Choose every workload that is backed up for it.' }],
      onSubmit: async (v) => { if (!v.external_ids.length) throw new Error('Choose at least one.'); for (const id of v.external_ids) await api('POST', `/servers/${server.id}/links`, { integration_id: Number(integrationId), external_id: id }); },
    });
    if (doneTenant) { toast('Linked. Status read from the platform.'); show(); }

    return;
  }
  const done = await formModal({
    title: `Link ${server.hostname} to a device`, submitLabel: 'Link',
    fields: [{ name: 'external_id', label: 'Device on the platform', type: 'select', options: free.map((d) => ({ value: d.id, label: `${d.name}${d.kind === 'vm' ? ' [VM]' : ''}${d.status ? ` (${d.status})` : ''}` })), hint: 'BMS will read this device’s backup status from the platform.' }],
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
// --- agent actions managed in BMS ---
// The commands BMS may start on a server. An admin edits them here instead of in the agent's config file. They run on that server
// with the agent's rights (root / SYSTEM), so the dialog says so, and every change is written to the audit log.
const commandText = (c) => (c ? c.join('\n') : '');
const commandParts = (t) => t.split('\n').map((x) => x.replace(/\r$/, '')).filter((x, i) => i === 0 || x !== '');

async function saveManaged(a, managed) {
  const actions = {};
  for (const [name, m] of Object.entries(managed)) actions[name] = { command: m.command, cwd: m.cwd ?? null, timeout_seconds: m.timeout_seconds ?? null };
  return api('PUT', `/servers/${a.server_id}/agent/actions`, { actions });
}

async function actionForm(a, managed, name) {
  const m = name ? managed[name] : null;
  const done = await formModal({
    title: name ? `Edit action "${name}"` : 'Add action', submitLabel: 'Save',
    fields: [
      { name: 'name', label: 'Action name', required: true, value: name ?? '', hint: name === 'backup' ? 'BMS starts the action named backup when you run a backup.' : 'Letters, numbers, dot, dash and underscore. Use "backup" for the one BMS starts by default.' },
      { name: 'command', label: 'Command: the program on the first line, then one argument per line', type: 'textarea', required: true, value: commandText(m?.command),
        hint: 'Run directly, not through a shell, so there is no quoting or && to worry about. Example: /usr/local/bin/backup.sh then --full on the next line. Do not put passwords here: they are stored in BMS and shown in the audit log.' },
      { name: 'cwd', label: 'Working folder (optional)', value: m?.cwd ?? '' },
      { name: 'timeout', label: 'Give up after (minutes)', value: String(Math.round((m?.timeout_seconds ?? 8 * 3600) / 60)) },
    ],
    onSubmit: async (v) => {
      const n = v.name.trim();
      if (name && n !== name) delete managed[name];
      const mins = Number(v.timeout);
      if (!Number.isFinite(mins) || mins < 1 || mins > 10080) throw new Error('The time limit must be between 1 minute and 7 days (10080 minutes).');
      managed[n] = { type: 'command', command: commandParts(v.command), cwd: v.cwd.trim() || null, timeout_seconds: Math.round(mins * 60) };
      await saveManaged(a, managed);
    },
  });
  return done;
}

async function locationPathForm(a, mapped, bmsLocations, key) {
  const free = bmsLocations.filter((l) => key === l.loc_key || !(l.loc_key in mapped));
  if (!free.length) { toast('Every backup location already has a folder on this server. Add locations on the Locations page.', true); return false; }
  return formModal({
    title: key ? `Folder for "${key}"` : 'Set a location folder', submitLabel: 'Save',
    fields: [
      { name: 'key', label: 'Backup location', type: 'select', options: free.map((l) => ({ value: l.loc_key, label: `${l.name} (${l.loc_key})` })), value: key ?? free[0].loc_key },
      { name: 'path', label: 'Folder on this server', required: true, value: key ? mapped[key] : '', hint: 'A full path as this server sees it, such as D:\\Backups, \\\\nas\\share\\backups or /mnt/nas/backups. Backups are written here and restore tests read from here.' },
    ],
    onSubmit: async (v) => {
      const next = { ...mapped };
      if (key && v.key !== key) delete next[key];
      next[v.key] = v.path.trim();
      await api('PUT', `/servers/${a.server_id}/agent/locations`, { locations: next });
    },
  });
}

async function sourceRootsForm(a, roots) {
  return formModal({
    title: 'Folders this agent may read', submitLabel: 'Save',
    fields: [{ name: 'roots', label: 'One full folder path per line', type: 'textarea', value: roots.join('\n'),
      hint: 'A job can only back up folders under these, and source comparison only reads under them. Subfolders are included. Example: D:\\Data or /srv/www. Leave empty to allow none.' }],
    onSubmit: async (v) => {
      const list = [...new Set(v.roots.split('\n').map((x) => x.trim()).filter(Boolean))];
      await api('PUT', `/servers/${a.server_id}/agent/source-roots`, { source_roots: list });
    },
  });
}

async function credentialForm(a, users, bmsLocations) {
  if (!bmsLocations.length) { toast('Add a backup location on the Locations page first.', true); return false; }
  return formModal({
    title: 'Sign-in for a network share', submitLabel: 'Save',
    fields: [
      { name: 'location', label: 'Backup location', type: 'select', options: bmsLocations.map((l) => ({ value: l.loc_key, label: `${l.name} (${l.loc_key})${l.loc_key in users ? ' \u2014 replaces ' + users[l.loc_key] : ''}` })) },
      { name: 'username', label: 'User name', required: true, hint: 'For example DOMAIN\\svc-backup. Use a dedicated account that can only read and write the backup share.' },
      { name: 'password', label: 'Password', type: 'password', required: true, hint: 'Stored encrypted in BMS and sent to the agent over HTTPS. It is never shown again, so to change it, enter it again.' },
    ],
    onSubmit: (v) => api('PUT', `/servers/${a.server_id}/agent/credentials`, { location: v.location, username: v.username.trim(), password: v.password }),
  });
}

async function editActions(a) {
  const draw = async () => {
    const fresh = ((await api('GET', '/agents')).data.agents.find((x) => x.id === a.id)) ?? a;
    const managed = structuredClone(fresh.managed_actions ?? {});
    const fileOnly = Object.entries(fresh.actions ?? {}).filter(([n]) => !(n in managed));
    const mapped = { ...(fresh.managed_locations ?? {}) };
    const bmsLocations = (await api('GET', '/backup-locations')).data;
    const fileLocs = (fresh.locations ?? []).filter((k) => !(k in mapped));
    const credUsers = { ...(fresh.credential_users ?? {}) };
    const roots = [...(fresh.managed_source_roots ?? [])];
    const fileRoots = (fresh.source_roots ?? []).filter((r) => !roots.includes(r));
    const note = fresh.remote_actions === false
      ? h('p', { class: 'error' }, 'This agent was installed with remote actions turned off, so only its own config file decides what it runs. Re-install without --no-remote-actions to manage actions here.')
      : fresh.remote_actions == null
        ? h('p', { class: 'error' }, 'This agent is too old to take actions from BMS. On the server, run the new bms_agent.py with: --upgrade --enable-remote-actions')
        : null;
    const body = h('div', {},
      h('p', { class: 'small muted' }, 'These commands run on the server with the agent’s rights (root or SYSTEM). The agent picks up a change within about a minute and no reinstall is needed. Changes are in the audit log.'),
      note,
      table([
        { title: 'Action', render: ([n]) => h('code', {}, n) },
        { title: 'Command', render: ([, m]) => (m.command ? h('code', { style: 'white-space:pre-wrap;word-break:break-all' }, m.command.join(' ')) : h('span', { class: 'muted' }, 'Not set up yet: runs nothing')) },
        { title: '', render: ([n]) => h('span', { class: 'row' },
          h('button', { class: 'link', onclick: async () => { if (await actionForm(fresh, managed, n)) { toast('Saved'); overlay.remove(); show(); } } }, 'Edit'),
          h('button', { class: 'link', onclick: async () => {
            if (!await confirmModal('Remove action?', `${n} will no longer be available from BMS.${n === 'backup' ? ' An empty backup action comes back so you can set it again.' : ''}`, 'Remove')) return;
            delete managed[n];
            if (await act(() => saveManaged(fresh, managed), 'Removed')) { overlay.remove(); show(); }
          } }, 'Remove')) },
      ], Object.entries(managed), { empty: 'None yet.' }),
      fileOnly.length ? h('p', { class: 'small muted' }, 'Also on this agent, set in its config file: ', fileOnly.map(([n]) => n).join(', '), '.') : null,
      h('h3', {}, 'Locations on this server'),
      h('p', { class: 'small muted' }, 'Which folder each backup location means on this server. A location set in the agent\u2019s own config file takes priority over one set here.'),
      table([
        { title: 'Location', render: ([k]) => h('code', {}, k) },
        { title: 'Folder', render: ([, p]) => h('code', { style: 'word-break:break-all' }, p) },
        { title: '', render: ([k]) => h('span', { class: 'row' },
          h('button', { class: 'link', onclick: async () => { if (await locationPathForm(fresh, mapped, bmsLocations, k)) { toast('Saved'); overlay.remove(); show(); } } }, 'Edit'),
          h('button', { class: 'link', onclick: async () => {
            const next = { ...mapped }; delete next[k];
            if (await act(() => api('PUT', `/servers/${fresh.server_id}/agent/locations`, { locations: next }), 'Removed')) { overlay.remove(); show(); }
          } }, 'Remove')) },
      ], Object.entries(mapped), { empty: 'None set here.' }),
      fileLocs.length ? h('p', { class: 'small muted' }, 'Set in the agent\u2019s config file: ', fileLocs.join(', '), '.') : null,
      h('div', { class: 'row' }, h('button', { disabled: fresh.remote_actions === false, onclick: async () => { if (await locationPathForm(fresh, mapped, bmsLocations, null)) { toast('Saved'); overlay.remove(); show(); } } }, 'Set a location folder')),
      h('h3', {}, 'Sign-in for network shares'),
      h('p', { class: 'small muted' }, 'The account the agent uses to open a location that is a network share. On Windows it signs in with this; without one it uses the account it runs as, which is usually SYSTEM and has no network access. On Linux (agent 1.10 or later) it mounts the share with this, which needs the agent to run as root and the cifs-utils package; the location is then written as \\\\server\\share\\folder in the Locations list or on this page. Passwords are encrypted in BMS and never shown again. Anyone who controls BMS can obtain them, so use a dedicated account with access to the backup share only.'),
      table([
        { title: 'Location', render: ([k]) => h('code', {}, k) },
        { title: 'Signs in as', render: ([, u]) => u },
        { title: '', render: ([k]) => h('button', { class: 'link', onclick: async () => {
          if (await act(() => api('PUT', `/servers/${fresh.server_id}/agent/credentials`, { location: k, remove: true }), 'Removed')) { overlay.remove(); show(); }
        } }, 'Remove') },
      ], Object.entries(credUsers), { empty: 'None set.' }),
      h('div', { class: 'row' }, h('button', { disabled: fresh.remote_actions === false, onclick: async () => { if (await credentialForm(fresh, credUsers, bmsLocations)) { toast('Saved'); overlay.remove(); show(); } } }, 'Set a sign-in')),
      h('h3', {}, 'Folders it may read'),
      h('p', { class: 'small muted' }, 'The folders a robocopy or rsync job may back up, and the only ones source comparison reads. A folder not listed here is refused.'),
      roots.length ? h('ul', {}, roots.map((r) => h('li', {}, h('code', {}, r)))) : h('p', { class: 'muted' }, 'None set here.'),
      fileRoots.length ? h('p', { class: 'small muted' }, 'Set in the agent’s config file: ', fileRoots.join(', '), '.') : null,
      h('div', { class: 'row' }, h('button', { disabled: fresh.remote_actions === false, onclick: async () => { if (await sourceRootsForm(fresh, roots)) { toast('Saved'); overlay.remove(); show(); } } }, 'Edit folders')),
      h('div', { class: 'actions' },
        h('button', { onclick: () => overlay.remove() }, 'Close'),
        h('button', { class: 'primary', disabled: fresh.remote_actions !== true && fresh.remote_actions !== null ? true : false, onclick: async () => { if (await actionForm(fresh, managed, null)) { toast('Saved'); overlay.remove(); show(); } } }, 'Add action')));
    return body;
  };
  const overlay = h('div', { class: 'overlay' }, h('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true', style: 'max-width:720px' }, h('h2', {}, `Actions, locations, sign-ins and folders on ${a.hostname}`), await draw()));
  document.body.append(overlay);
}

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
      { title: 'Server', render: (a) => h('div', {}, h('button', { class: 'link', onclick: () => serverDetail({ id: a.server_id }) }, a.hostname),
        isAdmin() && h('div', { class: 'row small' },
          h('button', { class: 'link', title: 'Remove this agent and get a new install command for the same server', onclick: () => replaceAgent(a) }, 'Re-enroll'),
          h('button', { class: 'link', onclick: () => removeAgent(a) }, 'Remove'))) },
      { title: 'Status', render: (a) => h('span', {}, badge(a.online ? 'success' : 'failed', a.online ? 'Online' : 'Offline'),
        a.running_commands ? ' working' : (a.pending_commands ? ` ${a.pending_commands} waiting` : '')) },
      { title: 'Last seen', render: (a) => fmtAgo(a.last_seen_at) },
      { title: 'Version', render: (a) => h('span', {}, a.agent_version ? `v${a.agent_version}` : '—', a.outdated ? h('span', { style: 'margin-left:6px' }, badge('warning', 'Update available')) : '') },
      { title: 'System', render: (a) => a.os_info ?? '—' },
      { title: 'Actions', render: (a) => h('span', {}, names(a.actions), a.managed_actions && a.remote_actions !== false ? h('button', { class: 'link', style: 'margin-left:8px', onclick: () => editActions(a) }, 'Edit') : '') },
      { title: 'Locations', render: (a) => (a.locations ?? []).join(', ') || '—' },
    ], d.agents, { empty: 'No agents yet. Enroll one for a server below.' })),
    card(`Servers without an agent (${d.servers_without_agent.length})`, table([
      { title: 'Server', render: (s) => s.hostname }, { title: 'OS', render: (s) => label(s.os_type) },
      { title: '', render: (s) => isAdmin() && h('button', { class: 'link', onclick: () => enrollAgent(s) }, 'Enroll and get install command') },
    ], d.servers_without_agent, { empty: 'Every server has an agent.' })),
    card('The agent program', d.release.available
      ? h('div', {},
        h('p', {}, `Version ${d.release.version ?? '?'} · `, h('a', { href: d.release.path, download: 'bms_agent.py' }, 'Download bms_agent.py'), ' · SHA-256 ', h('code', { style: 'word-break:break-all' }, d.release.sha256)),
        d.release.bundles?.length > 0 && h('p', { class: 'small' }, 'No Python on a Linux server? ', d.release.bundles.map((b) => h('span', {}, h('a', { href: b.path, download: '' }, `Download the agent with its own Python (${b.arch}, ${(b.size / 1048576).toFixed(0)} MB)`), ' · SHA-256 ', h('code', { style: 'word-break:break-all' }, b.sha256), ' ')),
          'Unpack it, then run ./bms-agent.sh --install … as root, in place of python3 bms_agent.py --install …'),
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

// Take an agent off a server. BMS stops giving it work and no longer accepts its secret; the software on the server keeps running until it is stopped there.
async function removeAgent(a) {
  if (!await confirmModal('Remove agent?', `${a.hostname} will stop receiving backup and test commands, and its secret stops working. Everything set for it in BMS (its locations, source folders, share sign-ins and actions) is deleted with it. The agent software on the server keeps running until you stop it there (python3 bms_agent.py --uninstall). To reinstall the agent and keep those settings, use Re-enroll instead.`, 'Remove')) return;
  if (await act(() => api('DELETE', `/servers/${a.server_id}/agent`), 'Agent removed')) show();
}
// A new secret and install command for the same agent. Its locations, source folders, share sign-ins and actions set in BMS are kept.
async function replaceAgent(a) {
  if (!await confirmModal('Re-enroll this agent?', `${a.hostname} gets a new secret and a new install command. The current secret stops working at once and anything the agent was doing is cancelled. What you set for it in BMS (locations, source folders, share sign-ins and actions) is kept. Install the new command on the server; it replaces the agent software that is there.`, 'Re-enroll')) return;
  await enrollAgent({ id: a.server_id, hostname: a.hostname }, true);
  show();
}
async function enrollAgent(server, rotate = false) {
  let r;
  try { r = await api('POST', `/servers/${server.id}/agent${rotate ? '/rotate' : ''}`); } catch (e) { return toast(errMessage(e), true); }
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
    h('p', { class: 'small muted' }, `Replace the folder after each location key with where that location is on this server. BMS can then run a backup once you set its command under Agents, Actions (or give --backup-script PATH to fix it in the config file). Agent version ${r.agent_release.version ?? '?'}, SHA-256 ${(r.agent_release.sha256 ?? '').slice(0, 16)}…`),
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
// Backup problems somebody has to review. A recovery does not close one: a later success does not make up for backups that were skipped.
const FAILURE_KIND = { failed: ['Failed', 'failed'], critical: ['Critical (platform)', 'failed'], overdue: ['Overdue', 'warning'], warning: ['Warning', 'info'] };
const FAILURE_FIX = [
  { value: 'rerun_succeeded', label: 'The next backup ran fine' }, { value: 'fixed', label: 'I fixed the cause' }, { value: 'platform_issue', label: 'A problem on the backup platform' },
  { value: 'false_positive', label: 'It was not really a problem' }, { value: 'known_issue', label: 'A known issue we are living with' },
];

async function reviewFailure(x) {
  const gap = x.missed_runs > 0 ? ` ${x.missed_runs} scheduled backup${x.missed_runs === 1 ? ' was' : 's were'} missed (about ${x.gap_hours} hours without a good backup). Note whether that gap matters.` : '';
  const done = await formModal({
    title: `Review: ${x.hostname}`, submitLabel: 'Close this problem',
    fields: [
      { name: 'resolution_type', label: 'What happened', type: 'select', options: FAILURE_FIX, value: x.active ? 'fixed' : 'rerun_succeeded', hint: `${x.message}${gap}` },
      { name: 'notes', label: 'What you found and what was done', type: 'textarea', required: true, hint: 'Kept in the audit log, so the next person can see why this was closed.' },
    ],
    onSubmit: (v) => api('POST', `/backup-failures/${x.id}/resolve`, { resolution_type: v.resolution_type, notes: v.notes.trim() }),
  });
  if (done) { toast('Reviewed'); show(); }
}

const failureCard = (rows) => card(`Backup problems to review (${rows.length})`,
  h('p', { class: 'small muted' }, 'A backup that failed, that its platform calls critical, that is overdue or has a warning stays here until a person reviews it, even if it recovers by itself. When backups were skipped, the gap is shown: a later success does not make up for them.'),
  table([
    { title: 'Server', render: serverCell },
    { title: 'Problem', render: (x) => badge(FAILURE_KIND[x.kind][1], FAILURE_KIND[x.kind][0]) },
    { title: 'What was found', render: (x) => h('span', { title: x.message, style: 'display:inline-block;max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom' }, x.message) },
    { title: 'Since', render: (x) => fmtAgo(x.first_seen_at) },
    { title: 'Now', render: (x) => (x.active ? badge('failed', 'Still a problem') : h('span', {}, badge('success', 'Recovered'), ` ${fmtAgo(x.recovered_at)}`)) },
    { title: 'Gap', render: (x) => (x.missed_runs > 0 ? badge('warning', `${x.missed_runs} missed \u00b7 ${x.gap_hours} h`) : x.gap_hours != null ? `${x.gap_hours} h` : '\u2014') },
    { title: '', render: (x) => me.role !== 'company_manager' && h('button', { class: 'link', onclick: () => reviewFailure(x) }, 'Review') },
  ], rows, { empty: 'Nothing to review.' }));

const PLATFORM_STATE = { success: ['success', 'Backed up'], warning: ['warning', 'No recent backup'], failed: ['failed', 'Failed'], unknown: ['info', 'Nothing reported yet'] };
// One list of every backup: the jobs BMS manages, and what the backup platform (Acronis) reports for each server linked to it. A platform row is what the
// platform said when BMS last read it; BMS does not run or check those backups. The records BMS keeps of platform backups are not listed here.
async function backups() {
  const [jobs, srv, failures, platform] = await Promise.all([api('GET', '/backup-jobs?limit=200&managed=1'), api('GET', '/servers?limit=500'), api('GET', '/backup-failures?limit=100'), api('GET', '/platform-backups')]);
  const addBtn = isAdmin() && h('button', { class: 'primary', onclick: () => jobForm(srv.data) }, 'New backup job');
  const rows = [...jobs.data.map((j) => ({ ...j, row: 'job' })), ...platform.data.map((p) => ({ ...p, row: 'platform' }))]
    .sort((a, b) => a.hostname.localeCompare(b.hostname) || (a.row === b.row ? 0 : a.row === 'job' ? -1 : 1));
  // A platform row acts on the newest record BMS holds for it, so the same test buttons work from either kind of row.
  const asJob = (r) => (r.row === 'job' ? r : { id: r.job_id, server_id: r.server_id, hostname: r.hostname, backup_method: r.method });
  const platformStatus = (r) => h('span', {}, badge(...PLATFORM_STATE[r.state] ?? ['info', label(r.state)]), r.platform_status && r.platform_status !== 'ok' ? h('div', { class: 'small muted' }, `Platform says: ${label(r.platform_status)}`) : '');
  return h('div', {}, topbar('Backups', null, addBtn), failureCard(failures.data), card(`Backups (${rows.length})`,
    h('p', { class: 'small muted' }, 'Backups BMS manages, and what the backup platform reports for each server linked to it, as BMS last read it. Link a server to its platform device from the server page.'),
    table([
      { title: 'Server', render: (r) => r.hostname },
      { title: 'Backup', render: (r) => (r.row === 'job' ? label(r.backup_method) : h('span', {}, methodName(r.method), h('div', { class: 'small muted' }, r.connection))) },
      { title: 'Backs up', render: (r) => (r.row === 'job' ? scopeCell(r) : h('span', {}, r.backup, r.kind === 'workload' && r.detail ? h('div', { class: 'small muted' }, r.detail) : '')) },
      { title: 'Location', render: (r) => (r.row === 'platform' ? h('span', { class: 'muted', title: 'Platform backups use the locations configured on the platform.' }, 'On the platform')
        : r.location_name ? (r.destination_path ? `${r.location_name} / ${r.destination_path}` : r.location_name) : (LOCATION_METHODS.includes(r.backup_method) ? h('span', { class: 'muted' }, 'None set') : h('span', { class: 'muted', title: 'Platform backups use the locations configured on the platform.' }, 'On the platform'))) },
      { title: 'Schedule', render: (r) => (r.row === 'job' && r.schedule ? h('code', {}, r.schedule) : '—') },
      { title: 'Status', render: (r) => (r.row === 'job' ? badge(r.status) : platformStatus(r)) },
      { title: 'Size', render: (r) => (r.row === 'job' && r.size_gb != null ? `${r.size_gb} GB` : '—') },
      { title: 'Last backup', render: (r) => (r.row === 'job' ? fmtAgo(r.completed_time) : h('span', {}, r.last_success_at ? fmtAgo(r.last_success_at) : 'None reported',
        r.sync_error ? h('div', { class: 'bad small' }, r.sync_error) : r.synced_at ? h('div', { class: 'small muted' }, `read ${fmtAgo(r.synced_at)}`) : '')) },
      { title: 'Tested', render: (r) => (r.test_status ? badge(r.test_status) : '—') },
      { title: '', render: (r) => { const j = asJob(r); return h('span', { class: 'row' },
        r.row === 'job' && isAdmin() && h('button', { class: 'link', onclick: async (e) => { e.stopPropagation(); if (await act(() => api('POST', `/backup-jobs/${j.id}/run`), 'Backup started. The agent picks it up within a minute.')) show(); } }, 'Run now'),
        canRunTests() && j.id && h('button', { class: 'link', onclick: (e) => { e.stopPropagation(); runTest(j); } }, 'Run test'),
        canRunTests() && h('button', { class: 'link', onclick: (e) => { e.stopPropagation(); manualTestForm(j); } }, 'Test by hand'),
        r.row === 'job' && isAdmin() && LOCATION_METHODS.includes(r.backup_method) && h('button', { class: 'link', onclick: (e) => { e.stopPropagation(); jobLocation(r); } }, 'Location'),
        r.row === 'job' && isAdmin() && h('button', { class: 'link', onclick: (e) => { e.stopPropagation(); jobScope(r); } }, 'Scope'),
        r.row === 'job' && isAdmin() && h('button', { class: 'link', onclick: (e) => { e.stopPropagation(); setStatus(r); } }, 'Update status'),
        r.row === 'job' && isAdmin() && h('button', { class: 'link', onclick: async (e) => { e.stopPropagation(); if (await confirmModal('Delete backup job?', `${r.hostname} / ${label(r.backup_method)}`, 'Delete') && await act(() => api('DELETE', `/backup-jobs/${r.id}`), 'Job deleted')) show(); } }, 'Delete')); } },
    ], rows, { empty: 'No backups yet. Add a backup job, or link a server to its backup platform.' })));
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
// --- tests: what each one does, and recurring schedules ---
let testTypeCache = null;
const testTypes = async () => (testTypeCache ??= (await api('GET', '/test-types')).data);
const forgetTestTypes = () => { testTypeCache = null; };
const testHelp = (types) => Object.fromEntries(types.map((t) => [t.type, t.automated ? `${t.summary} Runs: ${t.runs}` : `${t.summary} BMS cannot run this one: do it by hand. If queued here it is recorded as skipped.`]));
const testOptions = (types) => types.map((t) => ({ value: t.type, label: `${t.title}${t.automated ? '' : ' (not automated yet)'}` }));

async function runTest(j) {
  const types = (await testTypes()).filter((t) => t.active);
  if (!types.length) return toast('Every test is turned off for your company.', true);
  const done = await formModal({
    title: `Queue test for ${j.hostname}`, submitLabel: 'Queue test',
    fields: [{ name: 'test_type', label: 'Test type', type: 'select', options: testOptions(types), value: 'random_restore', help: testHelp(types) }],
    onSubmit: (v) => api('POST', `/backup-jobs/${j.id}/test`, { test_type: v.test_type }),
  });
  if (done) { toast('Test queued'); show(); }
}

// --- tests done by hand ---
// For backups BMS cannot read itself (Microsoft 365, platform backups) and for any test that is not automated. A person follows a checklist,
// attaches evidence (fingerprints, notes, files) and completes it as passed or failed. A pass needs every step ticked and the required
// evidence attached, and fingerprints that differ can never pass.
const MANUAL_STATE = { pending: ['Waiting', 'warning'], running: ['In progress', 'info'], success: ['Passed', 'success'], failed: ['Failed', 'failed'], skipped: ['Skipped', 'warning'], cancelled: ['Cancelled', 'warning'] };
const manualBadge = (status) => badge(MANUAL_STATE[status]?.[1] ?? 'info', MANUAL_STATE[status]?.[0] ?? label(status));
const HASH_ALGORITHMS = ['sha256', 'sha512', 'sha1', 'md5'];
const FILE_ACCEPT = '.png,.jpg,.jpeg,.pdf,.txt,.csv,.json,.log';
const fmtSize = (n) => (n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : n >= 1024 ? `${Math.round(n / 1024)} KB` : `${n} bytes`);

// --- the item to open, the record of it, and checking by a second person ---
const isShot = (e) => e.kind === 'file' && /\.(png|jpe?g)$/i.test(e.file_name ?? '');
const VERIFY_STATE = { pending: ['Awaiting a second check', 'warning'], confirmed: ['Checked and confirmed', 'success'], rejected: ['Rejected on review', 'failed'] };

// What the tester has to open: chosen at random by BMS when the test was created.
const selectionBox = (t) => t.selection && h('div', { class: 'refbox' },
  h('strong', {}, 'Which item: '), t.selection.text,
  h('div', { class: 'small muted', style: 'margin-top:4px' }, t.selection.fallback));

// The tester records the item as fields. BMS checks they hold together before a pass is allowed.
const findingsForm = (t, paint) => {
  if (!t.fields?.length) return null;
  const inputs = {};
  const row = (f) => {
    const id = `finding_${f.id}`;
    const common = { id, disabled: !t.can_edit };
    let input;
    if (f.type === 'select') {
      input = h('select', common, [h('option', { value: '' }, 'Choose…'), ...Object.entries(f.options).map(([v, text]) => h('option', { value: v }, text))]);
      input.value = f.value ?? '';
    } else {
      input = h('input', { ...common, type: f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : f.type === 'time' ? 'time' : 'text', value: f.value ?? '' });
    }
    inputs[f.id] = input;
    return h('div', { class: 'field' }, h('label', { for: id }, f.label, f.required ? ' *' : ''), input);
  };
  const form = h('form', { class: 'findings', onsubmit: async (e) => {
    e.preventDefault();
    const body = {};
    for (const f of t.fields) body[f.id] = inputs[f.id].value === '' ? null : (f.type === 'number' ? Number(inputs[f.id].value) : inputs[f.id].value);
    if (await act(() => api('PUT', `/manual-tests/${t.id}/findings`, body), 'Details saved')) paint();
  } },
    t.fields.map(row),
    t.can_edit && h('button', { class: 'primary', type: 'submit' }, 'Save details'));
  return h('div', {}, h('h3', {}, 'Details of the item'),
    h('p', { class: 'small muted' }, 'Enter these from the backup console and the restored item. Times are in the tenant’s time zone. BMS checks they agree with each other and with the item you were asked to open, and the screenshots are the proof someone can look at.'),
    form);
};

// A second person's decision on a test picked for checking.
const verifyPanel = (t, paint) => {
  const v = t.verification;
  const state = v?.state && VERIFY_STATE[v.state];
  return [
    state && h('p', {}, h('strong', {}, 'Second check: '), badge(state[1], state[0]), v.verified_by ? ` by ${v.verified_by}` : '', v.note ? h('span', { class: 'muted' }, ` — ${v.note}`) : ''),
    t.can_verify && h('div', { class: 'refbox' },
      h('strong', {}, 'This test was picked for a second check. '),
      'Look at the screenshots and the details above. Confirm if the screenshots show what the details say: the item, its date, subject and size, and a restore that completed. Reject if they do not, and say why. A rejection turns the pass into a failure.',
      h('div', { class: 'row', style: 'margin-top:8px' },
        h('button', { class: 'primary', onclick: async () => { if (await act(() => api('POST', `/manual-tests/${t.id}/verify`, { decision: 'confirm' }), 'Confirmed')) paint(); } }, 'Confirm the evidence'),
        h('button', { class: 'danger', onclick: async () => {
          const done = await formModal({ title: 'Reject the evidence', submitLabel: 'Reject and fail the test', fields: [{ name: 'note', label: 'What is wrong', type: 'textarea', required: true, hint: 'Shown to the admins and kept in the audit log.' }],
            onSubmit: (x) => api('POST', `/manual-tests/${t.id}/verify`, { decision: 'reject', note: x.note.trim() }) });
          if (done) { toast('Recorded as failed'); paint(); } } }, 'Reject'))),
    isAdmin() && t.test_status === 'success' && !v?.state && h('p', {}, h('button', { class: 'link', onclick: async () => { if (await act(() => api('POST', `/manual-tests/${t.id}/request-verification`), 'Sent for a second check')) paint(); } }, 'Ask for a second check')),
  ];
};

const verifyCard = (rows) => card(`Waiting for a second check (${rows.length})`,
  h('p', { class: 'small muted' }, 'A share of passed tests done by hand, picked at random, is checked by a second person who looks at the screenshots and details. You cannot check your own.'),
  table([
    { title: 'Server', render: serverCell },
    { title: 'Test', render: (x) => label(x.test_type) },
    { title: 'Sample', render: (x) => x.target ?? '—' },
    { title: 'Done by', render: (x) => x.assigned_username ?? '—' },
    { title: 'Completed', render: (x) => fmtAgo(x.completed_time) },
    { title: '', render: (x) => h('button', { class: 'link', onclick: () => openManualTest(x.id) }, x.can_verify ? 'Review' : 'View') },
  ], rows, { empty: 'Nothing is waiting to be checked.' }));

async function manualTestForm(job) {
  const types = (await testTypes()).filter((t) => t.active);
  if (!types.length) return toast('Every test is turned off for your company.', true);
  let created = null;
  const done = await formModal({
    title: `Test by hand: ${job.hostname}`, submitLabel: 'Create test',
    fields: [
      { name: 'test_type', label: 'Test', type: 'select', hint: job.source, options: types.map((t) => ({ value: t.type, label: t.title })), value: job.backup_method === 'acronis_m365' || job.backup_method === 'microsoft_native_365' ? 'm365_mailbox' : 'random_restore',
        help: Object.fromEntries(types.map((t) => [t.type, t.summary.replace(/^Not built: would /, 'You ')])) },
      { name: 'target', label: 'What to sample (optional)', hint: 'A mailbox, site or folder. Leave it empty and BMS picks one from the tenant’s list, the one tested least recently.' },
    ],
    onSubmit: async (v) => { created = (await api('POST', job.id ? `/backup-jobs/${job.id}/manual-test` : `/servers/${job.server_id}/manual-test`, { test_type: v.test_type, target: v.target.trim() || null })).data; },
  });
  if (done && created) { toast('Test created'); show(); openManualTest(created.id); }
}

async function openManualTest(id) {
  const overlay = h('div', { class: 'overlay' });
  document.body.append(overlay);
  const close = () => { overlay.remove(); show(); };
  const paint = async () => {
    let t;
    try { t = (await api('GET', `/manual-tests/${id}`)).data; } catch (e) { overlay.remove(); return toast(errMessage(e), true); }
    const box = overlay.firstElementChild;
    const keep = box ? box.scrollTop : 0;
    const modal = h('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true', style: 'max-width:880px;max-height:90vh;overflow:auto' }, manualBody(t, paint, close));
    overlay.replaceChildren(modal);
    modal.scrollTop = keep;
  };
  await paint();
}

const evidenceView = (t, e, paint, step = null) => h('li', { style: 'margin:4px 0' },
  e.kind === 'note' ? h('span', {}, h('strong', {}, `${e.label}: `), e.value_a)
    : e.kind === 'hash' ? h('span', {}, h('strong', {}, `${e.label}: `), `${e.algorithm.toUpperCase()} `, h('code', { style: 'word-break:break-all' }, e.value_a),
      e.value_b ? h('span', {}, step?.reference ? ' expected, restored ' : ' / ', h('code', { style: 'word-break:break-all' }, e.value_b), ' ', e.value_a === e.value_b ? badge('success', 'Match') : badge('failed', 'Differ')) : h('span', { class: 'muted' }, ' (no restored value yet)'))
      : h('span', {}, h('strong', {}, `${e.label}: `), h('a', { href: `/api/manual-tests/${t.id}/evidence/${e.id}/file`, download: e.file_name }, e.file_name), ` (${fmtSize(e.file_size ?? 0)})`,
        isShot(e) ? h('div', {}, h('a', { href: `/api/manual-tests/${t.id}/evidence/${e.id}/file?inline=1`, target: '_blank', rel: 'noopener' },
          h('img', { src: `/api/manual-tests/${t.id}/evidence/${e.id}/file?inline=1`, alt: `Screenshot: ${e.label}`, style: 'max-width:280px;max-height:170px;border:1px solid var(--line);border-radius:4px;margin-top:4px' }))) : null),
  t.can_edit && h('button', { class: 'link', style: 'margin-left:8px', onclick: async () => { if (await act(() => api('DELETE', `/manual-tests/${t.id}/evidence/${e.id}`), 'Removed')) paint(); } }, 'Remove'));

async function addNoteEvidence(t, stepId, paint) {
  const done = await formModal({
    title: 'Add a note', submitLabel: 'Add',
    fields: [{ name: 'label', label: 'Title', required: true, value: stepId ? '' : 'Note' }, { name: 'text', label: 'Note', type: 'textarea', required: true }],
    onSubmit: (v) => api('POST', `/manual-tests/${t.id}/evidence`, { kind: 'note', step_id: stepId, label: v.label.trim(), text: v.text.trim() }),
  });
  if (done) paint();
}

async function addHashEvidence(t, stepId, paint) {
  const done = await formModal({
    title: 'Add fingerprints (hashes)', submitLabel: 'Add',
    fields: [
      { name: 'label', label: 'What was hashed', required: true, hint: 'The file or item, for example Q3-report.xlsx.' },
      { name: 'algorithm', label: 'Algorithm', type: 'select', options: HASH_ALGORITHMS.map((a) => ({ value: a, label: a.toUpperCase() })), value: 'sha256' },
      { name: 'original', label: 'Original', required: true, hint: 'Paste the hash exactly. Spaces and capitals are fine. It must be the right length for the algorithm.' },
      { name: 'restored', label: 'Restored copy', hint: 'Leave empty if you have not hashed the restored copy yet. If both are given and they differ, the test cannot pass.' },
    ],
    onSubmit: (v) => api('POST', `/manual-tests/${t.id}/evidence`, { kind: 'hash', step_id: stepId, label: v.label.trim(), algorithm: v.algorithm, original: v.original, restored: v.restored.trim() || null }),
  });
  if (done) paint();
}

async function addRestoredFingerprint(t, stepId, paint) {
  const done = await formModal({
    title: 'Fingerprint of the restored copy', submitLabel: 'Compare and save',
    fields: [{ name: 'restored', label: `${t.reference.algorithm.toUpperCase()} of the restored ${t.reference.label}`, required: true, hint: 'Paste it exactly. Spaces and capitals are fine. BMS compares it with the expected fingerprint shown on the test, and a different one means the test cannot pass.' }],
    onSubmit: (v) => api('POST', `/manual-tests/${t.id}/evidence`, { kind: 'hash', step_id: stepId, label: t.reference.label, restored: v.restored.trim() }),
  });
  if (done) paint();
}

function attachFile(t, stepId, paint) {
  const input = h('input', { type: 'file', accept: FILE_ACCEPT, style: 'display:none' });
  input.onchange = async () => {
    const f = input.files[0];
    input.remove();
    if (!f) return;
    const fd = new FormData();
    fd.append('file', f);
    if (stepId) fd.append('step_id', stepId);
    fd.append('label', f.name);
    if (await act(() => api('POST', `/manual-tests/${t.id}/evidence-file`, fd), 'File attached')) paint();
  };
  document.body.append(input);
  input.click();
}

async function completeFailed(t, paint) {
  const done = await formModal({
    title: 'Complete as failed', submitLabel: 'Record failure',
    fields: [
      { name: 'failure_type', label: 'What kind of failure', type: 'select', options: [['restore_failed', 'Restore failed'], ['file_missing', 'Item or recovery point missing'], ['hash_mismatch', 'Fingerprints differ'], ['corruption', 'Data damaged'], ['access_denied', 'Access denied'], ['timeout', 'Timed out']].map(([value, label]) => ({ value, label })), value: 'restore_failed' },
      { name: 'severity', label: 'Severity', type: 'select', options: opts(['critical', 'high', 'medium', 'low']), value: 'high' },
      { name: 'notes', label: 'What went wrong', type: 'textarea', required: true, hint: 'Shown to whoever reviews the failure, and kept in the audit log.' },
    ],
    onSubmit: (v) => api('POST', `/manual-tests/${t.id}/complete`, { result: 'failed', failure_type: v.failure_type, severity: v.severity, notes: v.notes.trim() }),
  });
  if (done) { toast('Failure recorded'); paint(); }
}

function manualBody(t, paint, close) {
  const ro = !t.can_edit;
  const setStep = async (stepId, done) => {
    const list = t.steps.map((s) => ({ id: s.id, done: s.id === stepId ? done : s.done }));
    if (await act(() => api('PUT', `/manual-tests/${t.id}`, { checklist: list }))) paint();
  };
  const ticked = t.steps.filter((s) => s.done).length;
  return [
    h('h2', {}, t.title, ' ', manualBadge(t.test_status)),
    h('table', { class: 'kv' }, h('tbody', {},
      h('tr', {}, h('th', {}, 'Server'), h('td', {}, `${t.hostname} (${methodName(t.backup_method)})`)),
      h('tr', {}, h('th', {}, t.target_label), h('td', {}, t.target ? h('strong', {}, t.target) : h('span', { class: 'muted' }, 'Not set. Choose one yourself and note it below.'))),
      h('tr', {}, h('th', {}, 'Being done by'), h('td', {}, t.assigned_username ?? 'Nobody yet')),
      h('tr', {}, h('th', {}, 'Progress'), h('td', {}, `${ticked} of ${t.steps.length} steps ticked`)))),
    t.reference && h('div', { class: 'refbox' }, h('strong', {}, 'Reference item: '), t.reference.label, t.reference.where ? ` (${t.reference.where})` : '',
      h('div', { class: 'small' }, `Expected ${t.reference.algorithm.toUpperCase()}: `, h('code', { style: 'word-break:break-all;user-select:all' }, t.reference.hash)),
      h('div', { class: 'small muted' }, 'Restore this item, work out the fingerprint of the restored copy and enter it below. You do not need the original or the mailbox owner.')),
    t.test_type === 'm365_mailbox' && !t.reference && h('p', { class: 'small muted' }, 'This tenant has no reference item, so there is no original to compare with. Restore an item from the test mailbox and check it is complete and opens. Ask an admin to add reference items for a stronger check.'),
    t.summary_help && h('p', { class: 'small muted', style: 'margin:10px 0' }, t.summary_help),
    t.test_status === 'pending' && h('p', {}, 'This test has not been started. Start it to take it on and tick off the steps.'),
    (t.result_summary || t.error_message) && h('p', {}, h('strong', {}, 'Result: '), t.result_summary ?? '', t.error_message ? h('span', {}, ' ', t.error_message) : ''),
    selectionBox(t),
    verifyPanel(t, paint),
    h('h3', {}, 'Checklist'),
    h('ol', { class: 'steplist' }, t.steps.map((s, i) => h('li', { class: s.done ? 'done' : '' },
      h('label', { class: 'step-row' },
        h('span', { class: 'step-n' }, `${i + 1}.`),
        h('input', { type: 'checkbox', checked: s.done, disabled: ro, onchange: (e) => setStep(s.id, e.target.checked) }),
        h('span', { class: 'step-text' }, s.text, s.files ? h('span', { class: s.screenshots_attached >= s.files ? 'small muted' : 'small', style: s.screenshots_attached >= s.files ? '' : 'color:var(--warn,#d98e00)' }, ` (screenshot needed: ${s.screenshots_attached} of ${s.files} attached)`) : '', s.required && s.evidence !== 'none' ? h('span', { class: 'small muted' }, s.evidence === 'hash' ? ' (fingerprints needed to pass)' : ' (note needed to pass)') : '')),
      s.evidence_items.length ? h('ul', { class: 'step-evidence' }, s.evidence_items.map((e) => evidenceView(t, e, paint, s))) : null,
      t.can_edit && h('div', { class: 'row step-controls' },
        s.evidence === 'note' && h('button', { class: 'link', onclick: () => addNoteEvidence(t, s.id, paint) }, 'Add note'),
        s.evidence === 'hash' && (s.reference
          ? h('button', { class: 'link', onclick: () => addRestoredFingerprint(t, s.id, paint) }, s.evidence_items.length ? 'Replace restored fingerprint' : 'Enter restored fingerprint')
          : h('button', { class: 'link', onclick: () => addHashEvidence(t, s.id, paint) }, 'Add fingerprints')),
        h('button', { class: 'link', onclick: () => attachFile(t, s.id, paint) }, 'Attach file'))))),
    findingsForm(t, paint),
    h('h3', {}, 'Other notes and files'),
    t.other_evidence.length ? h('ul', {}, t.other_evidence.map((e) => evidenceView(t, e, paint))) : h('p', { class: 'muted' }, 'None.'),
    t.can_edit && h('div', { class: 'row' }, h('button', { class: 'link', onclick: () => addNoteEvidence(t, null, paint) }, 'Add note'), h('button', { class: 'link', onclick: () => addHashEvidence(t, null, paint) }, 'Add fingerprints'), h('button', { class: 'link', onclick: () => attachFile(t, null, paint) }, 'Attach file')),
    h('div', { class: 'actions' },
      (t.test_status === 'pending' || t.test_status === 'running') && h('button', { class: 'danger', onclick: async () => {
        const done = await formModal({ title: 'Cancel this test', submitLabel: 'Cancel test', fields: [{ name: 'reason', label: 'Why', required: true }], onSubmit: (v) => api('POST', `/manual-tests/${t.id}/cancel`, { reason: v.reason.trim() }) });
        if (done) { toast('Test cancelled'); paint(); } } }, 'Cancel test'),
      h('button', { onclick: close }, 'Close'),
      t.can_start && h('button', { class: t.test_status === 'pending' ? 'primary' : '', onclick: async () => { if (await act(() => api('POST', `/manual-tests/${t.id}/start`), t.test_status === 'pending' ? 'Started' : 'Taken over')) paint(); } }, t.test_status === 'pending' ? 'Start this test' : 'Take over'),
      t.can_edit && h('button', { class: 'danger', onclick: () => completeFailed(t, paint) }, 'Complete as failed'),
      t.can_edit && h('button', { class: 'primary', onclick: async () => {
        if (await act(() => api('POST', `/manual-tests/${t.id}/complete`, { result: 'passed' }), 'Test recorded as passed')) paint();
      } }, 'Complete as passed')),
  ];
}

const manualCard = (rows) => card(`Tests to do by hand (${rows.length})`,
  h('p', { class: 'small muted' }, 'For backups BMS cannot read itself, such as Microsoft 365 and platform backups, and for any test that is not automated. Open one to see what to sample and follow the checklist.'),
  table([
    { title: 'Server', render: serverCell },
    { title: 'Test', render: (x) => label(x.test_type) },
    { title: 'Sample', render: (x) => x.target ?? h('span', { class: 'muted' }, 'Your choice') },
    { title: 'Status', render: (x) => h('span', {}, manualBadge(x.test_status), x.assigned_username ? ` ${x.assigned_username}` : '') },
    { title: 'Waiting', render: (x) => fmtAgo(x.scheduled_time) },
    { title: '', render: (x) => h('button', { class: 'link', onclick: () => openManualTest(x.id) }, x.test_status === 'pending' && canRunTests() ? 'Open and start' : 'Open') },
  ], rows, { empty: 'Nothing waiting. Create one from the Backups page (Test by hand) or schedule them.' }));

const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const browserZone = () => { try { return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'; } catch { return 'UTC'; } };

async function scheduleForm(existing) {
  const types = (await testTypes()).filter((t) => t.active || t.type === existing?.test_type);
  const [servers, jobs] = await Promise.all([api('GET', '/servers?limit=1000'), api('GET', '/backup-jobs?limit=1000')]);
  const serverOptions = servers.data.filter((s) => s.active !== false).map((s) => ({ value: String(s.id), label: `${s.hostname} (${(s.backup_methods ?? []).map(methodName).join(', ') || 'no backup types'})` }));
  const jobOptions = jobs.data.map((j) => ({ value: String(j.id), label: `${j.hostname}: ${methodName(j.backup_method)} (#${j.id})` }));
  const kind = existing ? (existing.target_type === 'all' ? 'all' : existing.target_type === 'job' ? 'job' : 'servers') : 'all';
  const chosen = existing ? (existing.target_type === 'server' ? [String(existing.server_id)] : (existing.server_ids ?? []).map(String)) : [];
  const done = await formModal({
    title: existing ? `Edit schedule "${existing.name}"` : 'Schedule tests', submitLabel: 'Save',
    fields: [
      { name: 'name', label: 'Name', required: true, value: existing?.name ?? '', hint: 'For example Weekly restore check.' },
      { name: 'who', label: 'Done by', type: 'select', options: [{ value: 'auto', label: 'BMS, automatically' }, { value: 'manual', label: 'A person, by hand (appears under Tests to do by hand)' }], value: existing?.manual ? 'manual' : 'auto',
        hint: 'BMS can only run the tests marked Automated. Choose a person for the rest, for backups BMS cannot read such as Microsoft 365 and Acronis, or when you want a human to check.' },
      { name: 'test_type', label: 'Test', type: 'select', options: types.map((t) => ({ value: t.type, label: t.automated ? t.title : `${t.title} (by hand only)` })), value: existing?.test_type ?? 'random_restore', help: Object.fromEntries(types.map((t) => [t.type, t.automated ? `${t.summary} Runs: ${t.runs}` : `${t.summary.replace(/^Not built: would /, 'A person ')}`])) },
      { name: 'target_kind', label: 'What to test', type: 'select', value: kind, options: [{ value: 'all', label: 'Every server' }, { value: 'servers', label: 'Selected servers (choose below)' }, { value: 'job', label: 'One backup job (choose below)' }],
        hint: 'Each server\u2019s newest successful backup of a kind the test can use. File tests (restore, write and read-back, full read-back, permissions) cover backups BMS can read: rsync, robocopy and manual copies. A recovery point check, and any test done by a person, covers every type, each one separately, including Acronis.' },
      { name: 'servers', label: 'Servers (for Selected servers)', type: 'checks', stacked: true, selectAll: true, hint: 'The Microsoft 365 tests (mailbox, OneDrive, SharePoint site) apply to Microsoft 365 tenants only, and a tenant needs no backup job: choose the tenant here, or Every server.', options: serverOptions, value: chosen },
      { name: 'job', label: 'Backup job (for One backup job)', type: 'select', options: jobOptions.length ? jobOptions : [{ value: '', label: 'No backup jobs yet' }], value: existing?.backup_job_id ? String(existing.backup_job_id) : (jobOptions[0]?.value ?? '') },
      { name: 'frequency', label: 'How often', type: 'select', options: [{ value: 'daily', label: 'Every day' }, { value: 'weekly', label: 'Every week' }, { value: 'monthly', label: 'Every month' }, { value: 'custom', label: 'Custom (cron)' }], value: existing?.frequency ?? 'weekly' },
      { name: 'run_time', label: 'Time of day (24 hour, HH:MM)', value: existing?.run_time ?? '02:00', hint: 'Not used for a custom schedule.' },
      { name: 'weekday', label: 'Day of the week (weekly only)', type: 'select', options: WEEKDAYS.map((d, i) => ({ value: String(i), label: d })), value: String(existing?.frequency === 'weekly' ? existing.run_day : 1) },
      { name: 'monthday', label: 'Day of the month (monthly only, 1 to 28)', value: String(existing?.frequency === 'monthly' ? existing.run_day : 1) },
      { name: 'cron', label: 'Cron expression (custom only)', value: existing?.frequency === 'custom' ? existing.cron : '', hint: 'Five fields, for example 0 2 * * 1-5 for 02:00 on weekdays. At most once an hour.' },
      { name: 'timezone', label: 'Time zone', value: existing?.timezone ?? browserZone(), hint: 'The time of day above is in this zone, for example Africa/Johannesburg or UTC.' },
    ],
    onSubmit: async (v) => {
      const body = { name: v.name.trim(), test_type: v.test_type, manual: v.who === 'manual', target_type: v.target_kind, frequency: v.frequency, timezone: v.timezone.trim() };
      if (v.target_kind === 'servers') body.server_ids = v.servers.map(Number);
      if (v.target_kind === 'job') body.backup_job_id = Number(v.job);
      if (v.frequency === 'custom') body.cron = v.cron.trim();
      else {
        body.run_time = v.run_time.trim();
        if (v.frequency === 'weekly') body.run_day = Number(v.weekday);
        if (v.frequency === 'monthly') body.run_day = Number(v.monthday);
      }
      await (existing ? api('PUT', `/test-schedules/${existing.id}`, body) : api('POST', '/test-schedules', body));
    },
  });
  if (done) { toast('Schedule saved'); show(); }
}

const scheduleCard = (rows) => card(`Test schedules (${rows.length})`,
  h('p', { class: 'small muted' }, 'Recurring tests. Each one queues its tests at the set time and the engine runs them within a minute. If BMS was off at the time, the schedule runs once when it is back. Times are shown in your browser’s zone.'),
  table([
    { title: 'Name', render: (s) => s.name },
    { title: 'Tests', render: (s) => h('span', {}, s.target_name, s.methods?.length ? h('div', { class: 'small muted' }, s.methods.map(methodName).join(', ')) : '') },
    { title: 'Test', render: (s) => (s.manual ? h('span', {}, label(s.test_type), ' ', badge('info', 'By hand')) : label(s.test_type)) },
    { title: 'Repeats', render: (s) => s.describe },
    { title: 'Next run', render: (s) => (s.enabled ? fmtDate(s.next_run_at) : badge('warning', 'Paused')) },
    { title: 'Last run', render: (s) => (s.last_run_at ? `${fmtAgo(s.last_run_at)}, ${s.last_queued} queued` : 'Never') },
    { title: '', render: (s) => isAdmin() && h('span', { class: 'row' },
      h('button', { class: 'link', onclick: async () => { if (await act(() => api('PUT', `/test-schedules/${s.id}`, { enabled: !s.enabled }), s.enabled ? 'Paused' : 'Resumed')) show(); } }, s.enabled ? 'Pause' : 'Resume'),
      h('button', { class: 'link', onclick: () => scheduleForm(s) }, 'Edit'),
      h('button', { class: 'link', onclick: async () => {
        try { const r = await api('POST', `/test-schedules/${s.id}/run`); toast(r.data.queued ? `${r.data.queued} test${r.data.queued === 1 ? '' : 's'} queued` : 'Nothing to test right now: nothing is eligible, or a test is already queued.'); show(); } catch (e) { toast(errMessage(e), true); }
      } }, 'Run now'),
      h('button', { class: 'link', onclick: async () => { if (await confirmModal('Delete schedule?', `${s.name} will stop running. Tests already queued are not removed.`, 'Delete') && await act(() => api('DELETE', `/test-schedules/${s.id}`), 'Schedule deleted')) show(); } }, 'Delete')) },
  ], rows, { empty: isAdmin() ? 'No schedules yet. Use Schedule tests to add one.' : 'No schedules yet.' }));

// Which tests this company uses. The platform admin offers a subset; the company admin turns off any of those it has no use for.
const settingsCard = (rows) => card('Tests this company uses',
  h('p', { class: 'small muted' }, 'Turn off a test your company does not need, for example one for a backup solution you do not have. A test that is off cannot be created, scheduled or run, and anything waiting on it is cancelled or skipped. Tests marked Not offered are decided by your platform administrator.'),
  table([
    { title: 'Test', render: (x) => h('span', {}, h('strong', {}, x.title), x.automated ? '' : h('span', { class: 'muted' }, ' (by hand)')) },
    { title: 'Status', render: (x) => (!x.available ? badge('failed', 'Not offered') : x.enabled ? badge('success', 'In use') : badge('warning', 'Turned off')) },
    { title: '', render: (x) => (!x.available ? h('span', { class: 'small muted' }, 'Ask your platform administrator') : h('button', { class: 'link', onclick: async () => {
      if (x.enabled && !await confirmModal(`Turn off ${x.title}?`, 'Open tests of this kind that people are doing by hand are cancelled, queued automatic ones are skipped, and its schedules stop queueing anything until it is turned back on. Results already recorded are kept.', 'Turn off')) return;
      if (await act(() => api('PUT', '/test-settings', { tests: { [x.type]: !x.enabled } }), x.enabled ? 'Turned off' : 'Turned on')) { forgetTestTypes(); show(); }
    } }, x.enabled ? 'Turn off' : 'Turn on')) },
  ], rows, { empty: 'No tests.' }));

async function companyTestsForm(c) {
  const rows = (await api('GET', `/platform/companies/${c.id}/test-settings`)).data;
  const done = await formModal({
    title: `Tests offered to ${c.name}`, submitLabel: 'Save',
    fields: [{ name: 'tests', label: 'Offered tests', type: 'checks', options: rows.map((r) => ({ value: r.type, label: `${r.title}${r.enabled ? '' : ' (the company has turned it off)'}` })), value: rows.filter((r) => r.available).map((r) => r.type),
      hint: 'Untick a test to remove it from this company. Their admin cannot turn it back on, and open tests of that kind are cancelled or skipped. A test the company has turned off for itself stays off if you offer it again.' }],
    onSubmit: (v) => api('PUT', `/platform/companies/${c.id}/test-settings`, { tests: Object.fromEntries(rows.map((r) => [r.type, v.tests.includes(r.type)])) }),
  });
  if (done) toast('Tests updated');
}

const testHelpCard = (types) => card('What each test does',
  h('p', { class: 'small muted' }, 'Tests marked Automated run by themselves. The others a person does by hand: use Test by hand on the Backups page, or schedule them as done by a person. Any test can be done by hand. If one that BMS cannot run is queued for BMS, it is recorded as skipped and never counted as a pass.'),
  types.map((t) => h('details', { style: 'margin:6px 0' },
    h('summary', {}, h('strong', {}, t.title), ' ', t.automated ? badge('success', 'Automated') : badge('info', 'By hand only'), !t.available ? h('span', { style: 'margin-left:6px' }, badge('failed', 'Not offered to your company')) : !t.enabled ? h('span', { style: 'margin-left:6px' }, badge('warning', 'Turned off')) : '', h('span', { class: 'muted' }, ` ${t.summary}`)),
    h('p', { class: 'small', style: 'margin:6px 0 6px 18px' }, t.details, h('br'), h('strong', {}, 'Runs: '), t.runs))));


// --- tests and failures ---
async function tests() {
  const canSee = me.role !== 'company_technician';
  const [t, f, types, sched, manual, verify, settings] = await Promise.all([api('GET', '/tests?limit=100'), api('GET', '/test-failures?resolved=false&limit=100'), testTypes(), canSee ? api('GET', '/test-schedules') : null, api('GET', '/manual-tests?status=open&limit=100'), api('GET', '/manual-tests?status=verify&limit=100'), isAdmin() ? api('GET', '/test-settings') : null]);
  const canResolve = me.role !== 'company_manager';
  return h('div', {}, topbar('Tests', 'Restore tests prove a backup can be read back. Schedule them here, or queue one from the Backups page.', isAdmin() && h('button', { class: 'primary', onclick: () => scheduleForm(null) }, 'Schedule tests')),
    manualCard(manual.data),
    verify.data.length ? verifyCard(verify.data) : null,
    sched && scheduleCard(sched.data),
    card(`Open failures (${f.pagination.total_count})`, table([
      { title: 'Server', render: serverCell }, { title: 'Test', render: (x) => label(x.test_type) },
      { title: 'Type', render: (x) => label(x.failure_type) }, { title: 'Severity', render: (x) => badge(x.severity) },
      { title: 'Item', render: (x) => x.failed_item ?? '—' }, { title: 'When', render: (x) => fmtAgo(x.created_at) },
      { title: '', render: (x) => canResolve && h('button', { class: 'link', onclick: () => resolveForm(x) }, 'Resolve') },
    ], f.data, { empty: 'No open failures.' })),
    card('Test history', table([
      { title: 'Server', render: serverCell }, { title: 'Type', render: (x) => (x.manual ? h('span', {}, label(x.test_type), ' ', badge('info', 'By hand'), x.assigned_username ? ` ${x.assigned_username}` : '', x.verify_state ? h('span', { style: 'margin-left:6px' }, badge(VERIFY_STATE[x.verify_state][1], x.verify_state === 'pending' ? 'Awaiting check' : `${x.verify_state === 'confirmed' ? 'Checked' : 'Rejected'} by ${x.verified_username}`)) : '') : label(x.test_type)) }, { title: 'Status', render: (x) => badge(x.test_status === 'success' ? 'passed' : x.test_status, label(x.test_status)) },
      { title: 'Hash match', render: (x) => (x.hash_match == null ? '—' : x.hash_match ? 'Yes' : 'No') },
      { title: 'Compared with source', render: (x) => (x.source_status === 'verified' ? badge('success', 'Matches')
        : x.source_status === 'mismatch' ? badge('failed', 'Differs')
          : x.source_status === 'not_comparable' ? h('span', { class: 'muted', title: x.source_note ?? '' }, 'Not compared') : '—') }, { title: 'Duration', render: (x) => (x.duration_seconds != null ? `${x.duration_seconds}s` : '—') },
      { title: 'Found', render: (x) => { const t = x.summary || (x.test_status === 'failed' || x.test_status === 'skipped' ? x.error_message : null); return t ? h('span', { class: x.test_status === 'success' ? '' : 'muted', title: t, style: 'display:inline-block;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom' }, t) : '—'; } },
      { title: 'Queued', render: (x) => fmtAgo(x.created_at) },
      { title: 'Runs', render: (x) => (x.manual ? h('button', { class: 'link', onclick: () => openManualTest(x.id) }, ['pending', 'running'].includes(x.test_status) ? 'Open' : 'View evidence') : x.test_status === 'pending'
        ? h('span', {}, x.retry_of ? h('span', { title: 'A failed test is retried after a delay' }, `Retry ${x.retry_count + 1}, `) : '', dueIn(x.scheduled_time),
          canRunTests() && new Date(x.scheduled_time).getTime() - Date.now() > 90000 ? h('button', { class: 'link', style: 'margin-left:8px', onclick: async () => {
            if (await act(() => api('POST', `/tests/${x.id}/run-now`), 'Queued to run now')) show();
          } }, 'Run now') : '')
        : '') },
    ], t.data, { empty: 'No tests have run yet.' })),
    settings ? settingsCard(settings.data) : null,
    testHelpCard(types));
}
const dueIn = (v) => {
  const sec = (new Date(v).getTime() - Date.now()) / 1000;
  if (sec <= 90) return 'due now';
  if (sec < 3600) return `in ${Math.round(sec / 60)} min`;
  if (sec < 86400) return `in ${Math.round(sec / 360) / 10} h`;
  return `in ${Math.round(sec / 8640) / 10} d`;
};
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
  const [r, sched] = await Promise.all([api('GET', '/reports?limit=100'), api('GET', '/report-schedules')]);
  return h('div', {}, topbar('Reports', 'Each report is saved as a snapshot. Open it, or download it as a PDF. Use the anonymised PDF to send a report outside the company: the company, servers and staff are not named, and each server is shown by what it stores.', h('button', { class: 'primary', onclick: reportForm }, 'Generate report')),
    card(null, table([
      { title: 'Report', render: (x) => REPORT_LABEL[x.report_type] },
      { title: 'Period', render: (x) => (x.report_type === 'dr_readiness' ? 'As at generation' : `${fmtDate(x.period_start)} to ${fmtDate(new Date(new Date(x.period_end) - 1))}`) },
      { title: 'Generated', render: (x) => `${fmtDate(x.created_at)}${x.generated_by ? '' : ' (automatic)'}` },
      { title: '', render: (x) => h('span', {}, h('button', { class: 'link', onclick: () => window.open(`/api/reports/${x.id}/view`, '_blank', 'noopener') }, 'Open'), ' · ', h('button', { class: 'link', onclick: () => downloadReport(x.id, false) }, 'PDF'), ' · ', h('button', { class: 'link', title: 'Names the company, servers and staff are replaced', onclick: () => downloadReport(x.id, true) }, 'Anonymised PDF')) },
    ], r.data, { empty: 'No reports yet. Generate one, or wait for the monthly and quarterly ones.' })),
    reportScheduleCard(sched.data, sched.periods));
}
const reportScheduleCard = (rows, periods) => card(`Scheduled reports (${rows.length})`,
  h('div', { class: 'row', style: 'justify-content:space-between' },
    h('p', { class: 'small muted' }, 'Reports that make themselves. Each one is saved with the others, and admins get a notification when it is ready. A schedule the engine was too late for makes one report when it is back. Periods run in UTC.'),
    h('button', { class: 'primary', onclick: () => reportScheduleForm(null, periods) }, 'Schedule a report')),
  table([
    { title: 'Name', render: (s) => s.name }, { title: 'Report', render: (s) => REPORT_LABEL[s.report_type] },
    { title: 'Covers', render: (s) => s.period_text }, { title: 'Repeats', render: (s) => s.describe },
    { title: 'Next run', render: (s) => (s.enabled ? fmtDate(s.next_run_at) : badge('warning', 'Paused')) },
    { title: 'Last run', render: (s) => (s.last_error ? h('span', {}, badge('failed', 'Failed'), h('div', { class: 'small' }, s.last_error)) : s.last_run_at ? h('span', {}, fmtAgo(s.last_run_at), s.last_report_id ? h('span', { class: 'small muted' }, ` \u00b7 report ${s.last_report_id}`) : '') : 'Never') },
    { title: '', render: (s) => h('span', { class: 'row' },
      h('button', { class: 'link', onclick: async () => { if (await act(() => api('PUT', `/report-schedules/${s.id}`, { enabled: !s.enabled }), s.enabled ? 'Paused' : 'Resumed')) show(); } }, s.enabled ? 'Pause' : 'Resume'),
      h('button', { class: 'link', onclick: () => reportScheduleForm(s, periods) }, 'Edit'),
      h('button', { class: 'link', onclick: async () => { if (await act(() => api('POST', `/report-schedules/${s.id}/run`), 'Report made')) show(); } }, 'Run now'),
      h('button', { class: 'link', onclick: async () => { if (await confirmModal('Delete schedule?', `${s.name} will stop making reports. Reports already made are kept.`, 'Delete') && await act(() => api('DELETE', `/report-schedules/${s.id}`), 'Schedule deleted')) show(); } }, 'Delete')) },
  ], rows, { empty: 'No scheduled reports yet.' }));
async function reportScheduleForm(existing, periods) {
  const done = await formModal({
    title: existing ? `Edit "${existing.name}"` : 'Schedule a report', submitLabel: 'Save',
    fields: [
      { name: 'name', label: 'Name', required: true, value: existing?.name ?? '', hint: 'For example Monthly compliance.' },
      { name: 'report_type', label: 'Report', type: 'select', options: Object.entries(REPORT_LABEL).map(([value, label]) => ({ value, label })), value: existing?.report_type ?? 'compliance' },
      { name: 'period', label: 'Period it covers', type: 'select', options: Object.entries(periods).map(([value, label]) => ({ value, label })), value: existing?.period ?? 'previous_month', hint: 'Ignored for DR readiness, which is a snapshot of the moment it is made.' },
      { name: 'frequency', label: 'How often', type: 'select', options: [{ value: 'weekly', label: 'Every week' }, { value: 'monthly', label: 'Every month' }, { value: 'quarterly', label: 'Every quarter' }, { value: 'custom', label: 'Custom (cron)' }], value: existing?.frequency ?? 'monthly' },
      { name: 'run_time', label: 'Time of day (24 hour, HH:MM)', value: existing?.run_time ?? '06:00', hint: 'Not used for a custom schedule.' },
      { name: 'weekday', label: 'Day of the week (weekly only)', type: 'select', options: WEEKDAYS.map((d, i) => ({ value: String(i), label: d })), value: String(existing?.frequency === 'weekly' ? existing.run_day : 1) },
      { name: 'monthday', label: 'Day of the month (monthly and quarterly, 1 to 28)', value: String(['monthly', 'quarterly'].includes(existing?.frequency) ? existing.run_day : 1) },
      { name: 'cron', label: 'Cron expression (custom only)', value: existing?.frequency === 'custom' ? existing.cron : '', hint: 'Five fields, for example 0 6 1 * * for 06:00 on the 1st. At most once a day.' },
      { name: 'timezone', label: 'Time zone', value: existing?.timezone ?? browserZone(), hint: 'The time of day above is in this zone.' },
    ],
    onSubmit: async (v) => {
      const body = { name: v.name.trim(), report_type: v.report_type, frequency: v.frequency, timezone: v.timezone.trim() };
      if (v.report_type !== 'dr_readiness') body.period = v.period;
      if (v.frequency === 'custom') body.cron = v.cron.trim();
      else {
        body.run_time = v.run_time.trim();
        if (v.frequency === 'weekly') body.run_day = Number(v.weekday);
        else body.run_day = Number(v.monthday);
      }
      await (existing ? api('PUT', `/report-schedules/${existing.id}`, body) : api('POST', '/report-schedules', body));
    },
  });
  if (done) { toast('Schedule saved'); show(); }
}
const downloadReport = (id, anonymise) => { window.location.href = `/api/reports/${id}/pdf${anonymise ? '?anonymise=1' : ''}`; };
async function reportForm() {
  let made = null;
  const done = await formModal({
    title: 'Generate report', submitLabel: 'Generate',
    fields: [
      { name: 'report_type', label: 'Report', type: 'select', options: Object.entries(REPORT_LABEL).map(([value, label]) => ({ value, label })) },
      { name: 'period', label: 'Period', type: 'select', options: Object.entries(PERIODS).map(([value, p]) => ({ value, label: p.label })), hint: 'Ignored for DR readiness, which is a snapshot of right now.' },
      { name: 'after', label: 'When it is ready', type: 'select', value: 'none', options: [{ value: 'none', label: 'Just save it' }, { value: 'pdf', label: 'Save it and download the PDF' }, { value: 'anon', label: 'Save it and download an anonymised PDF (for sending outside the company)' }],
        hint: 'Either PDF can be downloaded again later from the list. The saved report keeps the names; only the anonymised PDF leaves them out.' },
    ],
    onSubmit: (v) => {
      const [from, to] = PERIODS[v.period].range();
      return api('POST', '/reports', v.report_type === 'dr_readiness' ? { report_type: v.report_type } : { report_type: v.report_type, period_start: new Date(from).toISOString(), period_end: new Date(to).toISOString() })
        .then((r) => { made = { id: r.data.id, after: v.after }; return r; });
    },
  });
  if (done) {
    toast(made?.after === 'none' ? 'Report generated' : 'Report generated, downloading the PDF');
    if (made && made.after !== 'none') downloadReport(made.id, made.after === 'anon');
    show();
  }
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
      { title: 'Microsoft 365 backups', render: (i) => (i.workloads.length === 0 ? '—' : h('div', {}, i.workloads.map((w) => h('div', { class: 'small' }, h('strong', {}, `${w.label}: `), badge(w.state === 'unknown' ? 'warning' : w.state, label(w.state)), ` ${w.items} items, ${w.ok} ok, ${w.warning} warnings, ${w.failed} failed`,
        w.failed > 0 && Object.keys(w.errors).length > 0 ? ` (${Object.keys(w.errors).join(', ')})` : '', w.catching_up ? ' · still catching up' : '')))) },
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
        options: open.map((d) => ({ value: d.id, label: `${d.name}${d.kind === 'vm' ? ' (VM)' : ''} · ${d.status ? label(d.status) : 'no status'} · ${d.plans.length ? d.plans.join(', ') : 'no plan'}${d.matches_server_id ? ' · links to the existing server of this name' : ''}${d.suggested_hostname ? '' : ' · name unusable'}` })),
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

// --- one dashboard for every company (platform admins only) ---
async function overview() {
  const { companies: rows, totals: t } = (await api('GET', '/platform/dashboard')).data;
  const pct = (v) => (v === null ? '\u2014' : `${v}%`);
  return h('div', {}, topbar('All companies', 'How every company is doing, those that need attention first. Only counts are shown here. Open a company to see its servers and failures; that visit is recorded in its audit log.'),
    h('div', { class: 'grid' },
      metric('Companies', t.companies, '', `${t.needing_attention} need attention`), metric('Need attention', t.needing_attention, t.needing_attention ? 'bad' : 'ok', t.needing_attention ? 'See below' : 'All clear'),
      metric('Servers', t.servers, t.servers_failing ? 'bad' : '', `${t.servers_failing} failing`), metric('Backup problems open', t.open_backup_problems, t.open_backup_problems ? 'bad' : 'ok', ''),
      metric('Test failures open', t.open_test_failures, t.open_test_failures ? 'bad' : 'ok', ''), metric('Tests waiting', t.tests_waiting, '', 'By hand, not yet done')),
    card(null, table([
      { title: 'Company', render: (c) => h('span', {}, h('strong', {}, c.name), c.attention ? h('div', { class: 'small' }, c.attention_reasons.join(' \u00b7 ')) : h('div', { class: 'small muted' }, c.servers.total ? 'Nothing needs attention' : 'No servers yet')) },
      { title: 'Health', render: (c) => (c.health_score === null ? h('span', { class: 'muted' }, 'No data') : badge(c.health_score >= 90 ? 'success' : c.health_score >= 80 ? 'warning' : 'failed', String(c.health_score))) },
      { title: 'Servers', render: (c) => `${c.servers.total}${c.servers.failing ? ` (${c.servers.failing} failing)` : ''}` },
      { title: 'Backups, 30 days', render: (c) => `${pct(c.backups_30d.success_rate_pct)} of ${c.backups_30d.finished}${c.backups_30d.failed ? ` \u00b7 ${c.backups_30d.failed} failed` : ''}` },
      { title: 'Tests, 30 days', render: (c) => `${pct(c.tests_30d.pass_rate_pct)} of ${c.tests_30d.total}` },
      { title: 'Open problems', render: (c) => `${c.open_backup_failures} backup \u00b7 ${c.open_test_failures} test` },
      { title: 'Last backup', render: (c) => (c.last_backup_at ? fmtAgo(c.last_backup_at) : 'Never') },
      { title: '', render: (c) => (c.id === me.company.id ? h('span', { class: 'small muted' }, me.acting ? 'Open now' : 'Your company') : h('button', { class: 'link', onclick: () => enterCompany(c) }, 'Open')) },
    ], rows, { empty: 'No active companies.' })));
}

// --- companies (platform admins only) ---
async function companies() {
  const r = await api('GET', '/platform/companies');
  return h('div', {}, topbar('Companies', 'Every company on this system. Open one to work inside it as its admin. Each visit is recorded in that company’s audit log.', h('button', { class: 'primary', onclick: companyForm }, 'Create company')),
    card(null, table([
      { title: 'Company', render: (c) => c.name }, { title: 'Slug', render: (c) => c.slug }, { title: 'Plan', render: (c) => label(c.subscription_tier) },
      { title: 'Users', render: (c) => c.users }, { title: 'Servers', render: (c) => `${c.servers}${c.max_servers ? ` / ${c.max_servers}` : ''}` },
      { title: 'Status', render: (c) => badge(c.is_active ? 'success' : 'failed', c.is_active ? 'Active' : 'Disabled') }, { title: 'Created', render: (c) => fmtDate(c.created_at) },
      { title: '', render: (c) => h('span', { class: 'row' }, h('button', { class: 'link', onclick: () => companyTestsForm(c) }, 'Tests'),
        c.id === me.company.id ? h('span', { class: 'small muted' }, me.acting ? 'Open now' : 'Your company') : h('button', { class: 'link', onclick: () => enterCompany(c) }, 'Open')) },
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
  const name = (u) => [u.first_name, u.last_name].filter(Boolean).join(' ') || '—';
  return h('div', {}, topbar('Users', `People in ${me.company.company_name}`, h('button', { class: 'primary', onclick: userForm }, 'Add user')),
    card(null, table([
      { title: 'Username', render: (u) => h('span', {}, u.username, u.granted_access ? h('div', { class: 'small muted' }, 'Access granted by a platform admin') : '') },
      { title: 'Name', render: (u) => name(u) }, { title: 'Email', render: (u) => u.email ?? '—' }, { title: 'Role', render: (u) => ROLE_LABEL[u.role] },
      { title: 'Status', render: (u) => badge(u.active ? 'success' : 'failed', u.active ? 'Active' : 'Disabled') }, { title: 'Last login', render: (u) => fmtAgo(u.last_login) },
      { title: 'Two-step', render: (u) => (u.mfa_enabled == null ? '\u2014' : u.mfa_enabled ? badge('success', 'On') : badge('warning', 'Not set up')) },
      { title: '', render: (u) => (u.granted_access ? h('span', { class: 'small muted' }, 'Managed by a platform admin')
        : u.username === me.user.username ? h('button', { class: 'link', onclick: () => editUser(u, true) }, 'Edit my details')
          : h('span', { class: 'row' },
            h('button', { class: 'link', onclick: () => editUser(u) }, 'Edit'),
            h('button', { class: 'link', onclick: () => resetPw(u) }, 'Reset password'),
            u.mfa_enabled && h('button', { class: 'link', onclick: () => resetMfa(`/users/${u.id}/mfa/reset`, u) }, 'Reset two-step'),
            h('button', { class: 'link', onclick: async () => { if (await act(() => api('PATCH', `/users/${u.id}`, { active: !u.active }), u.active ? 'User disabled' : 'User enabled')) show(); } }, u.active ? 'Disable' : 'Enable'))) },
    ], r.data)));
}
async function userForm() {
  const done = await formModal({
    title: 'Add user',
    fields: [
      { name: 'username', label: 'Username', required: true }, { name: 'email', label: 'Email', type: 'email', required: true },
      { name: 'first_name', label: 'First name' }, { name: 'last_name', label: 'Last name' }, { name: 'phone', label: 'Phone' },
      { name: 'role', label: 'Role', type: 'select', options: Object.entries(ROLE_LABEL).map(([value, l]) => ({ value, label: l })) },
      { name: 'password', label: 'Temporary password', type: 'password', required: true, hint: 'At least 12 characters. They can change it after logging in.' },
    ],
    onSubmit: (v) => api('POST', '/users', { username: v.username.trim(), email: v.email.trim(), first_name: v.first_name.trim() || undefined, last_name: v.last_name.trim() || undefined, phone: v.phone.trim() || undefined, role: v.role, password: v.password }),
  });
  if (done) { toast('User created'); show(); }
}
// Edit a person's details. Your own role and status cannot be changed here, so an admin cannot lock their company out.
async function editUser(u, self = false) {
  const fields = [
    { name: 'first_name', label: 'First name', value: u.first_name ?? '' }, { name: 'last_name', label: 'Last name', value: u.last_name ?? '' },
    { name: 'email', label: 'Email', type: 'email', required: true, value: u.email ?? '' }, { name: 'phone', label: 'Phone', value: u.phone ?? '' },
  ];
  if (!self) fields.push({ name: 'role', label: 'Role', type: 'select', options: Object.entries(ROLE_LABEL).map(([value, l]) => ({ value, label: l })), value: u.role });
  const done = await formModal({
    title: self ? 'Edit my details' : `Edit ${u.username}`, submitLabel: 'Save', fields,
    onSubmit: (v) => api('PATCH', `/users/${u.id}`, { first_name: v.first_name.trim() || null, last_name: v.last_name.trim() || null, email: v.email.trim(), phone: v.phone.trim() || null, ...(self ? {} : { role: v.role }) }),
  });
  if (done) { toast('Saved'); show(); }
}
// Lost phone: remove their two-step sign-in and sign them out everywhere. They set it up again at their next sign-in.
async function resetMfa(path, u) {
  if (await confirmModal('Reset two-step sign-in?', `${u.username} will be signed out everywhere and must set up their authenticator app again at their next sign-in. Only do this once you are sure it is really them asking.`, 'Reset')
    && await act(() => api('POST', path), 'Two-step sign-in reset')) show();
}
async function resetPw(u) {
  const done = await formModal({
    title: `Reset password for ${u.username}`, submitLabel: 'Reset',
    fields: [{ name: 'password', label: 'New password', type: 'password', required: true, hint: 'At least 12 characters.' }],
    onSubmit: (v) => api('PATCH', `/users/${u.id}`, { password: v.password }),
  });
  if (done) toast('Password reset');
}

// --- people across companies (platform admins) ---
async function people() {
  const r = await api('GET', '/platform/users?limit=200');
  const companyList = (await api('GET', '/platform/companies')).data;
  return h('div', {}, topbar('People', 'Everyone on this system. Give a person access to other companies with a role for each, or make them a platform admin (access to every company as admin). Each change is recorded in the affected company’s audit log.'),
    card(null, table([
      { title: 'Username', render: (u) => h('span', {}, u.username, u.is_platform_admin ? h('span', { style: 'margin-left:6px' }, badge('info', 'Platform admin')) : '') },
      { title: 'Name', render: (u) => [u.first_name, u.last_name].filter(Boolean).join(' ') || '—' },
      { title: 'Home company', render: (u) => u.company.name }, { title: 'Role there', render: (u) => ROLE_LABEL[u.role] },
      { title: 'Also has access to', render: (u) => (u.is_platform_admin ? 'Every company' : u.access.length ? u.access.map((a) => `${a.company} (${ROLE_LABEL[a.role]})`).join(', ') : '—') },
      { title: 'Status', render: (u) => badge(u.active ? 'success' : 'failed', u.active ? 'Active' : 'Disabled') },
      { title: 'Two-step', render: (u) => (u.mfa_enabled ? badge('success', 'On') : badge('warning', 'Not set up')) },
      { title: '', render: (u) => h('span', { class: 'row' },
        u.id !== me.id && u.mfa_enabled && h('button', { class: 'link', onclick: () => resetMfa(`/platform/users/${u.id}/mfa/reset`, u) }, 'Reset two-step'),
        !u.is_platform_admin && h('button', { class: 'link', onclick: () => accessForm(u, companyList) }, 'Company access'),
        u.id !== me.id && (u.is_platform_admin || (u.role === 'company_admin' && u.active)) && h('button', { class: 'link', onclick: () => togglePlatformAdmin(u) }, u.is_platform_admin ? 'Remove platform admin' : 'Make platform admin')) },
    ], r.data)));
}
async function accessForm(u, companies) {
  const others = companies.filter((c) => c.id !== u.company.id);
  if (!others.length) return toast('There are no other companies to give access to.', true);
  const current = Object.fromEntries(u.access.map((a) => [a.company_id, a.role]));
  const roleOptions = [{ value: '', label: 'No access' }, ...Object.entries(ROLE_LABEL).map(([value, l]) => ({ value, label: l }))];
  const done = await formModal({
    title: `Company access for ${u.username}`, submitLabel: 'Save',
    fields: others.map((c) => ({ name: `c${c.id}`, label: c.name, type: 'select', options: roleOptions, value: current[c.id] ?? '' })),
    onSubmit: (v) => api('PUT', `/platform/users/${u.id}/access`, { grants: others.filter((c) => v[`c${c.id}`]).map((c) => ({ company_id: c.id, role: v[`c${c.id}`] })) }),
  });
  if (done) { toast('Access saved'); show(); }
}
async function togglePlatformAdmin(u) {
  const making = !u.is_platform_admin;
  const ok = await confirmModal(making ? 'Make platform admin?' : 'Remove platform admin?',
    making ? `${u.username} will be able to work inside EVERY company as its admin, and create companies. Any per-company access they had is replaced by this.` : `${u.username} will lose access to other companies and can no longer create companies.`,
    making ? 'Make platform admin' : 'Remove');
  if (ok && await act(() => api('PATCH', `/platform/users/${u.id}/platform-admin`, { is_platform_admin: making }), making ? 'Now a platform admin' : 'Platform admin removed')) show();
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
  const rem = (await api('GET', '/reminders')).data.mine;
  return h('div', {}, topbar('My profile'),
    card(null, h('table', {}, h('tbody', {}, ...[['Username', me.user.username], ['Email', me.user.email], ['Company', me.company.company_name], ['Role', ROLE_LABEL[me.role]]].map(([k, v]) => h('tr', {}, h('th', {}, k), h('td', {}, v))))),
      h('div', { style: 'margin-top:14px' }, h('button', { onclick: changePw }, 'Change password'))),
    card('Two-step sign-in', me.mfa?.enabled
      ? h('div', {}, h('p', {}, badge('success', 'On'), ` Recovery codes left: ${me.mfa.recovery_codes_left}`, me.mfa.recovery_codes_left <= 2 ? ' \u2014 make new ones soon' : ''),
        h('div', { class: 'row' }, h('button', { onclick: changeAuthenticator }, 'Change authenticator app'), h('button', { onclick: newRecoveryCodes }, 'New recovery codes'),
          !me.mfa.required && h('button', { class: 'danger', onclick: turnOffMfa }, 'Turn off')),
        me.mfa.required && h('p', { class: 'small muted' }, 'Two-step sign-in is required here, so it cannot be turned off.'))
      : h('div', {}, h('p', {}, badge('failed', 'Off'), ' Add a second step to your sign-in.'), h('button', { class: 'primary', onclick: mfaSetupScreen }, 'Set up'))),
    card('Mail reminders', h('div', {}, h('p', {}, rem.mail_reminders ? badge('success', 'On') : badge('warning', 'Off'), rem.mail_reminders ? ' You get a mail once a day while something needs your attention.' : ' You get no reminder mails.'),
      h('div', { class: 'row' }, h('button', { onclick: async () => { if (await act(() => api('PUT', '/reminders/mine', { enabled: !rem.mail_reminders }), rem.mail_reminders ? 'Reminders turned off' : 'Reminders turned on')) show(); } }, rem.mail_reminders ? 'Turn off' : 'Turn on'),
        rem.has_email && h('button', { onclick: sendReminderNow }, 'Send me one now')))));
}
const sendReminderNow = async () => {
  try {
    const r = (await api('POST', '/reminders/send-now')).data;
    toast(r.delivers ? `Sent. It lists ${r.items} thing${r.items === 1 ? '' : 's'}.` : `Done, but mail is set to "${r.mailer}", which writes it to a log and delivers nothing.`, !r.delivers);
  } catch (e) { toast(errMessage(e), true); }
};
// --- mail reminders (admin) ---
const REMINDER_HOURS = Array.from({ length: 24 }, (_, i) => ({ value: String(i), label: `${String(i).padStart(2, '0')}:00` }));
async function reminders() {
  const d = (await api('GET', '/reminders')).data;
  const s = d.settings;
  const row = (k, v) => h('tr', {}, h('th', {}, k), h('td', {}, v));
  return h('div', {}, topbar('Mail reminders', 'A mail once a day to the people who have something to fix or do: backup problems and test failures nobody has dealt with, tests waiting to be done, and servers not tested for 30 days. Nothing is sent when there is nothing to say.',
    h('button', { class: 'primary', onclick: () => reminderForm(s) }, 'Change')),
  !d.transport.delivers && h('div', { class: 'error', role: 'status' }, `Mail is set to "${d.transport.mailer}", so reminders are written to a log and not delivered. Set MAIL_MAILER and the mail server details in the .env file on the server (this needs a person with access to it), then use Send me one now to check.`),
  card(null, h('table', {}, h('tbody', {}, row('Reminders', s.enabled ? badge('success', 'On') : badge('warning', 'Off')),
    row('Sent at', `${String(s.send_hour).padStart(2, '0')}:00 (${s.timezone})${s.weekdays_only ? ', weekdays only' : ', every day'}`),
    row('Goes to', `${d.recipients.length} ${d.recipients.length === 1 ? 'person' : 'people'}: admins${s.include_managers ? ', managers' : ''} and technicians (their own tests to do). Anyone can turn theirs off under My profile.`),
    row('Mailing now', h('button', { onclick: sendReminderNow }, 'Send me one now'))))),
  card('Recent', table([
    { title: 'For day', render: (r) => r.for_date.slice(0, 10) }, { title: 'Person', render: (r) => r.username },
    { title: 'Result', render: (r) => (r.status === 'sent' ? badge('success', 'Sent') : r.status === 'failed' ? badge('failed', 'Failed') : h('span', { class: 'muted' }, 'Nothing to say')) },
    { title: 'Things listed', render: (r) => r.items }, { title: 'Problem', render: (r) => r.error ?? '' },
  ], d.recent, { empty: 'Nothing sent yet.' })));
}
async function reminderForm(s) {
  const done = await formModal({
    title: 'Mail reminders', submitLabel: 'Save',
    fields: [
      { name: 'enabled', label: 'Send reminders', type: 'select', options: [{ value: 'yes', label: 'Yes' }, { value: 'no', label: 'No' }], value: s.enabled ? 'yes' : 'no' },
      { name: 'send_hour', label: 'Hour of the day', type: 'select', options: REMINDER_HOURS, value: String(s.send_hour) },
      { name: 'timezone', label: 'Time zone', value: s.timezone, hint: 'For example Africa/Johannesburg or UTC. The hour above is in this zone.' },
      { name: 'weekdays_only', label: 'Weekdays only', type: 'select', options: [{ value: 'yes', label: 'Yes, not on Saturday or Sunday' }, { value: 'no', label: 'No, every day' }], value: s.weekdays_only ? 'yes' : 'no' },
      { name: 'include_managers', label: 'Also send to managers', type: 'select', options: [{ value: 'no', label: 'No, admins (and technicians for their own tests)' }, { value: 'yes', label: 'Yes' }], value: s.include_managers ? 'yes' : 'no' },
    ],
    onSubmit: (v) => api('PUT', '/reminders', { enabled: v.enabled === 'yes', send_hour: Number(v.send_hour), timezone: v.timezone.trim(), weekdays_only: v.weekdays_only === 'yes', include_managers: v.include_managers === 'yes' }),
  });
  if (done) { toast('Saved'); show(); }
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
    await loadMe();
  } catch { loginScreen(); return; }
  if (me.mfa?.setup_required) mfaSetupScreen(); else show();
})();
