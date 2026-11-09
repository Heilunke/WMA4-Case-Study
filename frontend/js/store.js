/* E-Ticket data layer — talks to the PHP/MySQL REST API (backend/).
   Reads are synchronous against an in-memory snapshot loaded by `await Store.init()` (App.shell does this
   for every page). Every change is an API call that is awaited, after which the snapshot is re-fetched,
   so the UI always shows what the database actually holds. Business rules (inventory, ticket codes,
   RBAC, validation) live on the server; this file never decides them.

   API location: the PHP dev server serves the frontend at / and the API at /api.
   Under XAMPP (…/WMA4-Case-Study/frontend/…) it is …/WMA4-Case-Study/backend/public/index.php/api.
   Override by defining window.ETICKET_API before this script loads. */
(function (global) {
  const pad = (n, w = 3) => String(n).padStart(w, '0');
  const SKEY = 'eticket_session';

  function apiBase() {
    if (global.ETICKET_API) return global.ETICKET_API.replace(/\/$/, '');
    const i = location.pathname.indexOf('/frontend/');
    return i >= 0 ? location.pathname.slice(0, i) + '/backend/public/index.php/api' : '/api';
  }
  const BASE = apiBase();

  class ApiError extends Error {
    constructor(message, status, code) { super(message); this.status = status; this.code = code; }
  }

  let cache = null, csrf = '', me = null, reportData = null, settings = { hold_hours: 0 };

  async function api(method, path, body) {
    const headers = { Accept: 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (csrf) headers['X-CSRF-Token'] = csrf;
    let res;
    try {
      res = await fetch(BASE + path, { method, headers, credentials: 'same-origin', body: body === undefined ? undefined : JSON.stringify(body) });
    } catch (e) {
      throw new ApiError('Cannot reach the server. Check that the backend is running.', 0, 'network');
    }
    const text = await res.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; } catch (e) { throw new ApiError('The server sent an unexpected response.', res.status, 'bad_response'); }
    if (data && data.csrf) csrf = data.csrf;
    if (!res.ok) {
      const err = (data && data.error) || {};
      // Session expired / not logged in: forget the local user and go to the login page.
      if (res.status === 401 && path !== '/auth/login') {
        try { sessionStorage.removeItem(SKEY); } catch (e) {}
        const root = (document.body && document.body.dataset.root) || '';
        if (root) location.href = root + 'index.html'; // only the login page itself has an empty root
      }
      throw new ApiError(err.message || 'Request failed (' + res.status + ').', res.status, err.code);
    }
    return data;
  }

  function apply(d) {
    me = d.user; csrf = d.csrf || csrf; reportData = d.report || null; settings = d.settings || settings;
    cache = { users: d.users, events: d.events, ticket_types: d.ticket_types, reservations: d.reservations, attendee_details: d.attendee_details, payments: d.payments };
  }
  /* Load (or reload) everything the logged-in user may see. Returns the current user. */
  async function refresh() { apply(await api('GET', '/bootstrap')); return me; }
  const init = refresh;
  const db = () => { if (!cache) throw new Error('Store.init() has not finished yet.'); return cache; };

  /* ---------- read helpers (synchronous, from the snapshot) ---------- */
  const current = () => me;
  const users = () => db().users;
  const events = () => db().events.slice().sort((a, b) => (a.event_date + a.event_time).localeCompare(b.event_date + b.event_time));
  const eventById = id => db().events.find(e => e.event_ID === +id);
  const typesOf = id => db().ticket_types.filter(t => t.event_ID === +id);
  const typeById = id => db().ticket_types.find(t => t.ticket_ID === +id);
  const reservations = () => db().reservations.slice().sort((a, b) => b.reservation_ID - a.reservation_ID);
  const reservationsOf = uid => db().reservations.filter(r => r.user_ID === +uid);
  /* Unpaid Pending reservations are cancelled by the server after settings.hold_hours (0 = never).
     Returns the Date it will expire, or null when it will not. */
  const holdHours = () => settings.hold_hours || 0;
  function expiresAt(r) {
    const p = paymentOf(r.reservation_ID);
    if (!holdHours() || r.reservation_status !== 'pending' || !p || p.payment_status !== 'unpaid') return null;
    return new Date(new Date(r.reservation_date).getTime() + holdHours() * 3600e3);
  }
  const resById = id => db().reservations.find(r => r.reservation_ID === +id);
  const attendeesOf = rid => db().attendee_details.filter(a => a.reservation_ID === +rid);
  const paymentOf = rid => db().payments.find(p => p.reservation_ID === +rid);
  const userById = id => db().users.find(u => u.user_ID === +id);
  const resCode = r => 'RSV-' + pad(r.reservation_ID);
  const seats = eventId => {
    const t = typesOf(eventId);
    const left = t.reduce((s, x) => s + x.quantity_available, 0);
    const total = t.reduce((s, x) => s + x.quantity_available + x.quantity_sold, 0);
    return { left, total, sold: total - left };
  };
  /* Joined view used by tables */
  function resView(r) {
    const t = typeById(r.ticket_ID), e = eventById(t.event_ID), p = paymentOf(r.reservation_ID), u = userById(r.user_ID);
    const att = attendeesOf(r.reservation_ID);
    return { ...r, code: resCode(r), ticket: t, event: e, payment: p, user: u, attendees: att, checkedIn: att.filter(a => a.status).length };
  }
  const report = () => { if (!reportData) throw new Error('Reports are available to Administrators only.'); return reportData; };

  /* ---------- auth ---------- */
  const publicUser = u => ({ user_ID: u.user_ID, full_Name: u.full_Name, email: u.email, role: u.role });
  async function login(email, password) {
    const d = await api('POST', '/auth/login', { email: String(email).trim(), password });
    return publicUser(d.user);
  }
  async function register(full_Name, email, password) {
    full_Name = String(full_Name).trim(); email = String(email).trim();
    if (!full_Name || !email || password.length < 6) throw new Error('Enter your name, a valid email and a password of at least 6 characters.');
    // The server ignores any role we might send: public sign-up is ALWAYS an attendee.
    const d = await api('POST', '/auth/register', { full_Name, email, password });
    return publicUser(d.user);
  }
  async function logout() { try { await api('POST', '/auth/logout'); } catch (e) {} cache = null; me = null; csrf = ''; }
  async function setRole(userId, role) { await api('PUT', '/users/' + +userId + '/role', { role }); await refresh(); }

  /* ---------- passwords (no e-mail server: an Administrator hands the user a one-time reset code) ---------- */
  async function issueResetCode(userId) { return api('POST', '/users/' + +userId + '/reset-code'); } // {code, expires_at}
  async function resetPassword(email, code, password) {
    if (String(password).length < 6) throw new Error('The new password must be at least 6 characters.');
    await api('POST', '/auth/reset-password', { email: String(email).trim(), code: String(code).trim(), password });
  }
  async function changePassword(currentPassword, password) {
    if (String(password).length < 6) throw new Error('The new password must be at least 6 characters.');
    await api('POST', '/auth/change-password', { current_password: currentPassword, password });
  }

  /* ---------- reservations & inventory (rules enforced by the API) ----------
     PENDING and CONFIRMED reservations HOLD seats; CANCELLED releases them. */
  /* The server books it for the logged-in user (from the session), so no user id is sent. */
  async function reserve(ticketId, qty, attendees, method) {
    const d = await api('POST', '/reservations', {
      ticket_ID: +ticketId, quantity: +qty, payment_method: method,
      attendees: attendees.map(a => ({ full_name: a.full_name, contact_number: a.contact_number || '' }))
    });
    await refresh();
    return d.reservation;
  }
  async function confirm(rid) { await api('POST', '/reservations/' + +rid + '/confirm'); await refresh(); return resById(rid); }
  async function cancel(rid) { await api('POST', '/reservations/' + +rid + '/cancel'); await refresh(); return resById(rid); }
  async function markPaid(rid, method) { await api('POST', '/reservations/' + +rid + '/payment', { payment_method: method || 'Cash' }); await refresh(); return paymentOf(rid); }

  /* ---------- check-in / ticket validation ---------- */
  async function checkIn(code, eventId) {
    code = String(code || '').trim();
    if (!code) return { level: 'danger', msg: 'Enter or scan a ticket code.' };
    const res = await api('POST', '/checkin', { ticket_code: code, event_ID: eventId ? +eventId : null });
    await refresh();
    const out = { level: res.level, code: res.code, msg: res.message };
    if (res.attendee_ID) {
      out.attendee = db().attendee_details.find(a => a.attendee_ID === res.attendee_ID);
      out.reservation = resView(resById(res.reservation_ID));
    }
    if (res.code === 'already_used' && res.checked_in_at) {
      out.msg = 'Already checked in at ' + new Date(res.checked_in_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + '.';
    }
    return out;
  }
  async function undoCheckIn(attendeeId) { await api('POST', '/attendees/' + +attendeeId + '/undo-checkin'); await refresh(); }

  /* ---------- events (admin) ---------- */
  async function saveEvent(data, types) {
    const body = {
      event_name: data.event_name, category: data.category, description: data.description,
      event_date: data.event_date, event_time: data.event_time, venue: data.venue,
      ticket_types: types.map(t => ({ ticket_ID: t.ticket_ID || null, type_name: t.type_name, price: t.price, total: t.total }))
    };
    const d = data.event_ID ? await api('PUT', '/events/' + +data.event_ID, body) : await api('POST', '/events', body);
    await refresh();
    return eventById(d.event_ID);
  }
  async function deleteEvent(id) { await api('DELETE', '/events/' + +id); await refresh(); }

  global.Store = { init, refresh, current, db, users, events, eventById, typesOf, typeById, reservations, reservationsOf, resById, resView, resCode, attendeesOf, paymentOf, userById, seats, holdHours, expiresAt, login, register, logout, setRole, issueResetCode, resetPassword, changePassword, reserve, confirm, cancel, markPaid, checkIn, undoCheckIn, saveEvent, deleteEvent, report, ApiError };
})(typeof window !== 'undefined' ? window : globalThis);
