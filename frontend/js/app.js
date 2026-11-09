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
  const logout = () => { sessionStorage.removeItem(SKEY); location.href = root + 'index.html'; };
  const pill = s => `<span class="pill pill-${s}">${esc(s[0].toUpperCase() + s.slice(1))}</span>`;
  const home = u => root + (u.role === 'admin' ? 'admin/dashboard.html' : 'attendee/dashboard.html');

  const NAV = {
    admin: [['dashboard', 'Dashboard', 'speedometer2', 'admin/dashboard.html'], ['events', 'Manage Events', 'calendar-event', 'admin/events.html'], ['reservations', 'Reservations', 'ticket-perforated', 'admin/reservations.html'], ['checkin', 'Check-in', 'qr-code-scan', 'admin/checkin.html'], ['reports', 'Reports', 'bar-chart-line', 'admin/reports.html'], ['users', 'Users', 'people', 'admin/users.html']],
    attendee: [['dashboard', 'Dashboard', 'speedometer2', 'attendee/dashboard.html'], ['events', 'Browse Events', 'search', 'attendee/events.html'], ['reservations', 'My Reservations', 'ticket-perforated', 'attendee/my-reservations.html']]
  };

  /* Route guard + layout. Returns the <main> element to render into. */
  function shell(role, active) {
    const u = user();
    if (!u) { location.replace(root + 'index.html'); return null; }
    if (u.role !== role) { location.replace(home(u)); return null; } // RBAC: wrong role -> own home
    const links = NAV[role].map(([k, label, ic, href]) => `<a href="${root}${href}" class="${k === active ? 'active' : ''}" ${k === active ? 'aria-current="page"' : ''}><i class="bi bi-${ic}" aria-hidden="true"></i>${label}</a>`).join('');
    document.getElementById('app').innerHTML = `
      <div class="shell">
        <aside class="sidebar">
          <a class="brand" href="${home(u)}"><span class="brand-mark"><i class="bi bi-ticket-perforated-fill"></i></span>E-Ticket</a>
          <div class="role-tag">${role === 'admin' ? 'Administrator' : 'Attendee'}</div>
          <nav aria-label="Main">${links}</nav>
          <div class="user"><div><div class="name">${esc(u.full_Name)}</div><div class="small">${esc(u.email)}</div></div>
            <button class="btn btn-sm btn-outline-light mt-2" id="logoutBtn"><i class="bi bi-box-arrow-right"></i> Log out</button></div>
        </aside>
        <main class="main" id="view"></main>
      </div>
      <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toasts"></div>`;
    document.getElementById('logoutBtn').onclick = logout;
    return document.getElementById('view');
  }
  function toast(msg, kind = 'success') {
    const el = document.createElement('div');
    el.className = `toast align-items-center text-bg-${kind} border-0`; el.setAttribute('role', 'status');
    el.innerHTML = `<div class="d-flex"><div class="toast-body">${esc(msg)}</div><button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>`;
    document.getElementById('toasts').appendChild(el);
    const t = new bootstrap.Toast(el, { delay: 3500 }); t.show(); el.addEventListener('hidden.bs.toast', () => el.remove());
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
  return { root, esc, peso, fmtDate, fmtTime, fmtDT, user, setUser, logout, pill, home, shell, toast, flash, showFlash, qr, param, eventCard };
})();
