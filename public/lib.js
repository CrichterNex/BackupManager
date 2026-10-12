// Small DOM + API toolkit. Everything is built with createElement/textContent, never innerHTML,
// so server data cannot inject markup.

export function h(tag, attrs, ...children) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs ?? {})) {
    if (v === false || v == null) continue;
    if (k === 'class') el.className = v;
    else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
    else if (k === 'value') el.value = v;
    else el.setAttribute(k, v === true ? '' : v);
  }
  for (const c of children.flat(Infinity)) {
    if (c == null || c === false) continue;
    el.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  return el;
}

export class ApiError extends Error {
  constructor(status, body) { super(body?.error ?? `Request failed (${status})`); this.status = status; this.body = body; }
}

// A platform admin working inside another company. Kept per browser tab, so closing the tab returns to their own company.
export const getActing = () => { try { return sessionStorage.getItem('actingCompany'); } catch { return null; } };
export const setActing = (id) => { try { id ? sessionStorage.setItem('actingCompany', String(id)) : sessionStorage.removeItem('actingCompany'); } catch { /* storage blocked: stay in the home company */ } };
const actingHeader = () => (getActing() ? { 'X-Acting-Company': getActing() } : {});

let onUnauthorized = () => {};
export const setUnauthorizedHandler = (fn) => { onUnauthorized = fn; };

export async function api(method, path, body) {
  const res = await fetch(`/api${path}`, {
    method,
    credentials: 'same-origin',
    headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}), ...actingHeader() },
    body: body ? JSON.stringify(body) : undefined,
  });
  if (res.status === 204) return null;
  const data = await res.json().catch(() => null);
  if (!res.ok) {
    if (res.status === 401 && !path.startsWith('/auth/login')) onUnauthorized();
    throw new ApiError(res.status, data);
  }
  return data;
}

export function toast(message, bad = false) {
  const t = h('div', { class: `toast${bad ? ' bad' : ''}`, role: bad ? 'alert' : 'status' }, message);
  document.getElementById('toasts').append(t);
  setTimeout(() => t.remove(), 4500);
}

export const errMessage = (e) => {
  const d = e?.body?.details;
  return d?.length ? `${e.message}: ${d.map((x) => `${x.field} ${x.message}`).join('; ')}` : e.message;
};

export const fmtDate = (v) => (v ? new Date(v).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '—');
export const fmtAgo = (v) => {
  if (!v) return 'never';
  const s = Math.max(0, (Date.now() - new Date(v).getTime()) / 1000);
  if (s < 60) return 'just now';
  if (s < 3600) return `${Math.floor(s / 60)} min ago`;
  if (s < 86400) return `${Math.floor(s / 3600)} h ago`;
  return `${Math.floor(s / 86400)} d ago`;
};
export const label = (v) => (v ? String(v).replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()) : '—');

const TONE = {
  success: 'ok', passed: 'ok', healthy: 'ok', resolved: 'ok', warning: 'warn', pending_test: 'warn', test_overdue: 'warn', pending: 'warn',
  running: 'info', scheduled: 'info', failed: 'bad', failing: 'bad', critical: 'bad', high: 'bad', medium: 'warn',
};
export const badge = (value, text) => h('span', { class: `badge ${TONE[value] ?? ''}` }, text ?? label(value));

export function table(columns, rows, { onRow, empty = 'Nothing to show yet.' } = {}) {
  if (!rows.length) return h('div', { class: 'empty' }, empty);
  return h('div', { class: 'table-wrap' }, h('table', {},
    h('thead', {}, h('tr', {}, columns.map((c) => h('th', {}, c.title)))),
    h('tbody', {}, rows.map((r) => h('tr', onRow ? { class: 'click', tabindex: 0, onclick: () => onRow(r), onkeydown: (e) => e.key === 'Enter' && onRow(r) } : {},
      columns.map((c) => h('td', {}, c.render(r))))))));
}

/**
 * Modal form. fields: [{name,label,type,options,required,hint,value}]. onSubmit(values) may throw to show an error.
 */
export function formModal({ title, fields, submitLabel = 'Save', onSubmit }) {
  return new Promise((resolve) => {
    const err = h('div', { class: 'error', hidden: true, role: 'alert' });
    const inputs = {};
    const body = fields.map((f) => {
      let input;
      if (f.type === 'select') {
        input = h('select', { id: `f_${f.name}` }, f.options.map((o) => h('option', { value: o.value }, o.label)));
        input.value = f.value ?? f.options[0]?.value ?? '';
      } else if (f.type === 'checks') {
        const boxes = f.options.map((o) => h('input', { type: 'checkbox', value: o.value, checked: (f.value ?? []).includes(o.value) }));
        input = h('div', { class: 'checks' }, f.options.map((o, i) => h('label', {}, boxes[i], o.label)));
        input.values = () => boxes.filter((b) => b.checked).map((b) => b.value);
      } else if (f.type === 'textarea') {
        input = h('textarea', { id: `f_${f.name}`, rows: 3, value: f.value ?? '' });
      } else {
        input = h('input', { id: `f_${f.name}`, type: f.type ?? 'text', autocomplete: f.type === 'password' ? 'new-password' : 'off', value: f.value ?? '' });
      }
      inputs[f.name] = input;
      return h('div', { class: 'field' }, h('label', { for: `f_${f.name}` }, f.label, f.required ? ' *' : ''), input, f.hint && h('div', { class: 'hint' }, f.hint));
    });
    const close = (v) => { overlay.remove(); resolve(v); };
    const submit = h('button', { class: 'primary', type: 'submit' }, submitLabel);
    const form = h('form', { novalidate: true }, h('h2', {}, title), err, body,
      h('div', { class: 'actions' }, h('button', { type: 'button', onclick: () => close(false) }, 'Cancel'), submit));
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const values = {};
      for (const f of fields) {
        const el = inputs[f.name];
        values[f.name] = f.type === 'checks' ? el.values() : el.value;
        if (f.required && !String(values[f.name]).trim()) { err.hidden = false; err.textContent = `${f.label} is required`; return; }
      }
      submit.disabled = true;
      try { await onSubmit(values); close(true); }
      catch (ex) { err.hidden = false; err.textContent = errMessage(ex); submit.disabled = false; }
    });
    const overlay = h('div', { class: 'overlay', onclick: (e) => e.target === overlay && close(false), onkeydown: (e) => e.key === 'Escape' && close(false) }, h('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true' }, form));
    document.body.append(overlay);
    overlay.querySelector('input,select,textarea')?.focus();
  });
}

export const confirmModal = (title, message, action = 'Confirm') =>
  new Promise((resolve) => {
    const close = (v) => { overlay.remove(); resolve(v); };
    const overlay = h('div', { class: 'overlay', onclick: (e) => e.target === overlay && close(false) },
      h('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true' }, h('h2', {}, title), h('p', {}, message),
        h('div', { class: 'actions' }, h('button', { onclick: () => close(false) }, 'Cancel'), h('button', { class: 'danger', onclick: () => close(true) }, action))));
    document.body.append(overlay);
  });
