/* Shared UI helpers: session, guard, shell (sidebar), formatting, toasts, QR. */
const App = (() => {
  const root = document.body.dataset.root || '';
  const SKEY = 'eticket_session';
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const peso = n => Number(n) === 0 ? 'Free' : '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2 });
  const fmtDate = d => new Date(d + 'T00:00').toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
  const fmtTime = t => { const [h, m] = t.split(':'); const d = new Date(); d.setHours(+h, +m); return d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' }); };
  const fmtDT = iso => new Date(iso).toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  const user = () => { try { return JSON.parse(sessionStorage.getItem(SKEY)); } catch (e) { return null; } };
  const setUser = u => sessionStorage.setItem(SKEY, JSON.stringify(u));
  const logout = async () => { await Store.logout(); sessionStorage.removeItem(SKEY); location.href = root + 'index.html'; };
  const pill = s => `<span class="pill pill-${s}">${esc(s[0].toUpperCase() + s.slice(1))}</span>`;
  const home = u => root + (u.role === 'admin' ? 'admin/dashboard.html' : 'attendee/dashboard.html');

  const NAV = {
    admin: [['dashboard', 'Dashboard', 'speedometer2', 'admin/dashboard.html'], ['events', 'Manage Events', 'calendar-event', 'admin/events.html'], ['reservations', 'Reservations', 'ticket-perforated', 'admin/reservations.html'], ['checkin', 'Check-in', 'qr-code-scan', 'admin/checkin.html'], ['reports', 'Reports', 'bar-chart-line', 'admin/reports.html'], ['users', 'Users', 'people', 'admin/users.html']],
    attendee: [['dashboard', 'Dashboard', 'speedometer2', 'attendee/dashboard.html'], ['events', 'Browse Events', 'search', 'attendee/events.html'], ['reservations', 'My Reservations', 'ticket-perforated', 'attendee/my-reservations.html']]
  };

  /* Route guard + layout. Loads the data snapshot from the API, then returns the <main> element to render into
     (or null when it redirected). The SERVER decides who you are and what you may see; the role kept in
     sessionStorage is only used for the quick redirect below and is overwritten with the server's answer. */
  async function shell(role, active) {
    let u = user();
    if (!u) { location.replace(root + 'index.html'); return null; }
    try {
      u = await Store.init();           // 401 here -> Store sends us to the login page
    } catch (e) {
      if (e.status === 401) return null;
      document.getElementById('app').innerHTML = `<div class="container py-5"><div class="alert alert-danger" role="alert"><strong>Could not load your data.</strong> ${esc(e.message)} <button class="btn btn-sm btn-outline-danger ms-2" onclick="location.reload()">Retry</button></div></div>`;
      return null;
    }
    setUser(u);
    if (u.role !== role) { location.replace(home(u)); return null; } // RBAC: wrong role -> own home
    const links = NAV[role].map(([k, label, ic, href]) => `<a href="${root}${href}" class="${k === active ? 'active' : ''}" ${k === active ? 'aria-current="page"' : ''}><i class="bi bi-${ic}" aria-hidden="true"></i>${label}</a>`).join('');
    document.getElementById('app').innerHTML = `
      <div class="shell">
        <aside class="sidebar">
          <a class="brand" href="${home(u)}"><span class="brand-mark"><i class="bi bi-ticket-perforated-fill"></i></span>E-Ticket</a>
          <div class="role-tag">${role === 'admin' ? 'Administrator' : 'Attendee'}</div>
          <nav aria-label="Main">${links}</nav>
          <div class="user"><div><div class="name">${esc(u.full_Name)}</div><div class="small">${esc(u.email)}</div></div>
            <div class="d-flex flex-wrap gap-2 mt-2"><button class="btn btn-sm btn-outline-light" id="logoutBtn"><i class="bi bi-box-arrow-right"></i> Log out</button>
            <button class="btn btn-sm btn-outline-light" id="pwBtn"><i class="bi bi-key"></i> Password</button></div></div>
        </aside>
        <main class="main" id="view"></main>
      </div>
      <div class="modal fade" id="pwModal" tabindex="-1" aria-labelledby="pwTitle"><div class="modal-dialog"><form class="modal-content" id="pwForm" novalidate>
        <div class="modal-header"><h2 class="modal-title h5" id="pwTitle">Change password</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><div id="pwErr" class="alert alert-danger d-none" role="alert"></div>
          <div class="mb-3"><label class="form-label" for="pwCur">Current password</label><input class="form-control" id="pwCur" type="password" autocomplete="current-password" required></div>
          <div class="mb-3"><label class="form-label" for="pwNew">New password</label><input class="form-control" id="pwNew" type="password" minlength="6" autocomplete="new-password" required></div>
          <div class="mb-1"><label class="form-label" for="pwNew2">Repeat new password</label><input class="form-control" id="pwNew2" type="password" minlength="6" autocomplete="new-password" required></div>
          <div class="form-text">At least 6 characters. Your other signed-in devices will be logged out.</div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand">Change password</button></div>
      </form></div></div>
      <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toasts"></div>`;
    document.getElementById('logoutBtn').onclick = logout;
    const pwModal = new bootstrap.Modal(document.getElementById('pwModal')), pwForm = document.getElementById('pwForm'), pwErr = document.getElementById('pwErr');
    document.getElementById('pwBtn').onclick = () => { pwForm.reset(); pwErr.classList.add('d-none'); pwModal.show(); };
    pwForm.onsubmit = async ev => {
      ev.preventDefault();
      const v = id => document.getElementById(id).value, btn = pwForm.querySelector('.btn-brand');
      pwErr.classList.add('d-none');
      if (v('pwNew') !== v('pwNew2')) { pwErr.textContent = 'The new passwords do not match.'; pwErr.classList.remove('d-none'); return; }
      btn.disabled = true;
      try { await Store.changePassword(v('pwCur'), v('pwNew')); pwModal.hide(); toast('Password changed.'); }
      catch (e) { pwErr.textContent = e.message; pwErr.classList.remove('d-none'); }
      finally { btn.disabled = false; }
    };
    return document.getElementById('view');
  }
  function toast(msg, kind = 'success') {
    const el = document.createElement('div');
    el.className = `toast align-items-center text-bg-${kind} border-0`; el.setAttribute('role', 'status');
    el.innerHTML = `<div class="d-flex"><div class="toast-body">${esc(msg)}</div><button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>`;
    document.getElementById('toasts').appendChild(el);
    const t = new bootstrap.Toast(el, { delay: 3500 }); t.show(); el.addEventListener('hidden.bs.toast', () => el.remove());
  }
  /* In-app dialogs that replace the browser's confirm()/prompt() boxes so they match the app's look.
     They may open on top of another open Bootstrap modal (e.g. reservation details): the dialog is
     stacked above it, and the parent's focus trap is paused so keyboard focus can enter the dialog.

       await App.confirm({ title, message, ok, cancel, danger })            -> true | false
       await App.choose({ title, message, label, options, value, ok })      -> chosen string | null  */
  function dialog({ title, message = '', ok = 'OK', cancel = 'Cancel', danger = false, field = '' }, read = () => true) {
    return new Promise(resolve => {
      const parents = [...document.querySelectorAll('.modal.show')].map(m => bootstrap.Modal.getInstance(m)).filter(Boolean);
      parents.forEach(p => p._focustrap?.deactivate());
      const el = document.createElement('div');
      el.className = 'modal fade app-dialog'; el.tabIndex = -1;
      el.setAttribute('aria-labelledby', 'appDialogTitle');
      el.innerHTML = `<div class="modal-dialog modal-dialog-centered"><form class="modal-content" novalidate>
        <div class="modal-header"><h2 class="modal-title h5" id="appDialogTitle">${esc(title)}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">${message ? `<p class="mb-${field ? 3 : 0}">${esc(message)}</p>` : ''}${field}</div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${esc(cancel)}</button>
          <button class="btn ${danger ? 'btn-danger' : 'btn-brand'}" data-ok>${esc(ok)}</button></div></form></div>`;
      document.body.appendChild(el);
      const m = new bootstrap.Modal(el);
      let result = null;
      el.querySelector('form').onsubmit = e => { e.preventDefault(); const v = read(el); if (v !== false) { result = v; m.hide(); } };
      el.addEventListener('show.bs.modal', () => setTimeout(() => { const bd = [...document.querySelectorAll('.modal-backdrop')].at(-1); if (bd && parents.length) bd.classList.add('app-dialog-backdrop'); }));
      el.addEventListener('shown.bs.modal', () => (el.querySelector('select, input') || el.querySelector('[data-ok]')).focus());
      el.addEventListener('hidden.bs.modal', () => {
        m.dispose(); el.remove();
        if (parents.length) { document.body.classList.add('modal-open'); parents.forEach(p => p._focustrap?.activate()); }
        resolve(result);
      });
      m.show();
    });
  }
  const confirmDialog = opts => dialog(opts).then(v => v === true);
  function choose({ label, options, value, ...opts }) {
    const id = 'appDialogChoice';
    const field = `<label class="form-label" for="${id}">${esc(label)}</label>
      <select class="form-select" id="${id}">${options.map(o => `<option ${o === value ? 'selected' : ''}>${esc(o)}</option>`).join('')}<option value="__other">Other…</option></select>
      <input class="form-control mt-2 d-none" id="${id}Other" maxlength="100" placeholder="Type the payment method" aria-label="Other payment method">
      <div class="invalid-feedback">Enter the payment method.</div>`;
    const p = dialog({ ...opts, field }, el => {
      const sel = el.querySelector('select'), other = el.querySelector('input');
      if (sel.value !== '__other') return sel.value;
      const v = other.value.trim();
      other.classList.toggle('is-invalid', !v);
      return v || false;
    });
    const sel = document.getElementById(id), other = document.getElementById(id + 'Other');
    sel.onchange = () => { other.classList.toggle('d-none', sel.value !== '__other'); if (sel.value === '__other') other.focus(); };
    return p;
  }

  /* Flash message that survives a redirect */
  const flash = (m, k) => sessionStorage.setItem('eticket_flash', JSON.stringify([m, k || 'success']));
  const showFlash = () => { const f = sessionStorage.getItem('eticket_flash'); if (f) { sessionStorage.removeItem('eticket_flash'); toast(...JSON.parse(f)); } };
  function qr(text) {
    if (typeof qrcode === 'undefined') return `<div class="p-2 small text-dark">${esc(text)}</div>`;
    const q = qrcode(0, 'M'); q.addData(text); q.make(); return q.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
  }
  const param = k => new URLSearchParams(location.search).get(k);
  const bannerClass = cat => ({ Sports: 'c1', Concert: 'c2', Workshop: 'c3' }[cat] || '');
  const eventCard = (e, href) => {
    const s = Store.seats(e.event_ID), pct = s.total ? Math.round(s.sold / s.total * 100) : 0, types = Store.typesOf(e.event_ID);
    const min = Math.min(...types.map(t => t.price));
    return `<div class="col-md-6 col-xl-4"><article class="card event-card">
      <div class="event-banner ${bannerClass(e.category)}"><div class="admit">Admit one · ${esc(e.category)}</div><span>${esc(e.event_name)}</span></div>
      <div class="perf" aria-hidden="true"></div>
      <div class="p-3 d-flex flex-column gap-2 flex-grow-1">
        <div class="event-meta"><div><i class="bi bi-calendar3" aria-hidden="true"></i>${fmtDate(e.event_date)} · ${fmtTime(e.event_time)}</div><div><i class="bi bi-geo-alt" aria-hidden="true"></i>${esc(e.venue)}</div>
        <div><i class="bi bi-tag" aria-hidden="true"></i>${types.map(t => esc(t.type_name) + ' ' + peso(t.price)).join(' · ')}</div></div>
        <div class="mt-auto pt-2"><div class="d-flex justify-content-between small mb-1"><span>${s.left} of ${s.total} tickets left</span><span>${pct}% reserved</span></div>
        <div class="seat-bar ${s.left / s.total < .2 ? 'low' : ''}"><span style="width:${pct}%"></span></div></div>
        <a class="btn ${s.left ? 'btn-brand' : 'btn-outline-secondary disabled'} mt-2" href="${href}">${s.left ? 'Reserve tickets' : 'Sold out'}</a>
      <div class="barcode mt-1" aria-hidden="true"></div></div></article></div>`;
  };
  return { root, esc, peso, fmtDate, fmtTime, fmtDT, user, setUser, logout, pill, home, shell, toast, flash, showFlash, qr, param, eventCard, confirm: confirmDialog, choose };
})();
