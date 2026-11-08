/* E-Ticket mock data layer (browser localStorage).
   Mirrors the six tables in the proposal's ERD so it can later be swapped
   for the PHP/Laravel REST API (events + reservations endpoints).
   NOTE: passwords are stored in plain text ONLY because this is a front-end
   prototype. The real system must use password_hash()/bcrypt. */
(function (global) {
  const KEY = 'eticket_db_v1';
  const day = n => { const x = new Date(); x.setDate(x.getDate() + n); return x.toISOString().slice(0, 10); };
  const pad = (n, w = 3) => String(n).padStart(w, '0');
  const now = () => new Date().toISOString();

  let cache = null;
  const db = () => cache || (cache = load());
  function load() {
    try { const raw = localStorage.getItem(KEY); if (raw) return JSON.parse(raw); } catch (e) {}
    const fresh = seed(); localStorage.setItem(KEY, JSON.stringify(fresh)); return fresh;
  }
  function save() { localStorage.setItem(KEY, JSON.stringify(cache)); }
  function nextId(t) { const d = db(); d.seq[t] = (d.seq[t] || 0) + 1; return d.seq[t]; }

  function seed() {
    cache = { seq: {}, users: [], events: [], ticket_types: [], reservations: [], attendee_details: [], payments: [] };
    const u = (full_Name, email, password, role) => { const r = { user_ID: nextId('u'), full_Name, email, password, role, created_at: now() }; cache.users.push(r); return r; };
    const admin = u('Event Administrator', 'admin@eticket.test', 'admin123', 'admin');
    const a1 = u('Maria Santos', 'attendee@eticket.test', 'attendee123', 'attendee');
    const a2 = u('Juan Dela Cruz', 'juan@eticket.test', 'attendee123', 'attendee');
    const a3 = u('Ana Reyes', 'ana@eticket.test', 'attendee123', 'attendee');
    const ev = (event_name, category, off, event_time, venue, description, types) => {
      const e = { event_ID: nextId('e'), user_ID: admin.user_ID, event_name, category, event_date: day(off), event_time, venue, description };
      cache.events.push(e);
      types.forEach(([type_name, price, total]) => cache.ticket_types.push({ ticket_ID: nextId('t'), event_ID: e.event_ID, type_name, price, quantity_available: total, quantity_sold: 0 }));
      return e;
    };
    const fun = ev('Fun Run 2026', 'Sports', 21, '05:30', 'TSU Main Campus Oval, Tarlac City', '5K community fun run open to students and the public. Includes race bib, finisher shirt and hydration stations.', [['Regular', 0, 50]]);
    const summit = ev('CCS Tech Summit', 'Seminar', 14, '09:00', 'TSU Convention Hall, Tarlac City', 'A one-day conference on web, mobile and cloud development with industry speakers and a career fair.', [['Regular', 250, 300], ['VIP', 750, 40]]);
    ev('Campus Acoustic Night', 'Concert', 30, '18:00', 'TSU Amphitheater, Tarlac City', 'An evening of live acoustic performances by student bands and guest artists.', [['General Admission', 150, 200], ['VIP', 400, 30]]);
    ev('Web Dev Workshop: Laravel Basics', 'Workshop', 7, '13:00', 'CCS Computer Lab 2, TSU', 'Hands-on workshop covering routing, controllers and Eloquent. Bring your own laptop.', [['Regular', 100, 30]]);
    const T = id => cache.ticket_types.find(t => t.event_ID === id);
    // seed reservations through the real functions so inventory stays consistent
    const mk = (user, ev_, qty, names, confirmIt, paid) => {
      const r = reserve(user.user_ID, T(ev_.event_ID).ticket_ID, qty, names.map(n => ({ full_name: n, contact_number: '0917 000 ' + pad(Math.floor(Math.random() * 999), 4) })), 'Pay at venue');
      if (confirmIt) confirm(r.reservation_ID);
      if (paid) markPaid(r.reservation_ID, 'Cash');
      return r;
    };
    const r1 = mk(a1, summit, 2, ['Maria Santos', 'Carlo Santos'], true, true);
    mk(a2, fun, 3, ['Juan Dela Cruz', 'Liza Dela Cruz', 'Marco Dela Cruz'], true, true);
    mk(a3, summit, 1, ['Ana Reyes'], false, false);
    mk(a1, fun, 1, ['Maria Santos'], false, false);
    const r5 = mk(a2, summit, 1, ['Juan Dela Cruz'], false, false); cancel(r5.reservation_ID);
    checkIn(cache.attendee_details.find(x => x.reservation_ID === r1.reservation_ID).ticket_code);
    return cache;
  }

  /* ---------- helpers ---------- */
  const users = () => db().users;
  const events = () => db().events.slice().sort((a, b) => (a.event_date + a.event_time).localeCompare(b.event_date + b.event_time));
  const eventById = id => db().events.find(e => e.event_ID === +id);
  const typesOf = id => db().ticket_types.filter(t => t.event_ID === +id);
  const typeById = id => db().ticket_types.find(t => t.ticket_ID === +id);
  const reservations = () => db().reservations.slice().sort((a, b) => b.reservation_ID - a.reservation_ID);
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

  /* ---------- auth ---------- */
  function login(email, password) {
    const u = db().users.find(x => x.email.toLowerCase() === String(email).trim().toLowerCase());
    if (!u || u.password !== password) throw new Error('Incorrect email or password.');
    return { user_ID: u.user_ID, full_Name: u.full_Name, email: u.email, role: u.role };
  }
  function register(full_Name, email, password) {
    full_Name = String(full_Name).trim(); email = String(email).trim();
    if (!full_Name || !email || password.length < 6) throw new Error('Enter your name, a valid email and a password of at least 6 characters.');
    if (db().users.some(x => x.email.toLowerCase() === email.toLowerCase())) throw new Error('That email is already registered.');
    // Public sign-up is ALWAYS role = attendee; admins are created/promoted only by an Administrator.
    const r = { user_ID: nextId('u'), full_Name, email, password, role: 'attendee', created_at: now() };
    db().users.push(r); save();
    return { user_ID: r.user_ID, full_Name, email, role: 'attendee' };
  }
  function setRole(userId, role) {
    const u = userById(userId); if (!u) throw new Error('User not found.');
    if (u.role === 'admin' && role !== 'admin' && db().users.filter(x => x.role === 'admin').length < 2) throw new Error('At least one Administrator is required.');
    u.role = role; save();
  }

  /* ---------- reservations & inventory ----------
     Inventory rule (answers panel item 5):
       PENDING and CONFIRMED reservations both HOLD seats (quantity_available is reduced at submit time).
       CANCELLED releases the seats back. A reservation with any checked-in attendee cannot be cancelled. */
  function reserve(userId, ticketId, qty, attendees, method) {
    const t = typeById(ticketId); if (!t) throw new Error('Ticket type not found.');
    qty = +qty;
    if (!(qty >= 1 && qty <= 10)) throw new Error('You can reserve 1 to 10 tickets per transaction.');
    if (qty > t.quantity_available) throw new Error(t.quantity_available ? `Only ${t.quantity_available} ticket(s) left for ${t.type_name}.` : `${t.type_name} is sold out.`);
    if (attendees.length !== qty || attendees.some(a => !String(a.full_name).trim())) throw new Error('Enter the name of every attendee (one per ticket).');
    t.quantity_available -= qty; t.quantity_sold += qty;
    const total = +(t.price * qty).toFixed(2);
    const r = { reservation_ID: nextId('r'), user_ID: +userId, ticket_ID: t.ticket_ID, quantity: qty, total_amount: total, reservation_status: 'pending', reservation_date: now() };
    db().reservations.push(r);
    attendees.forEach(a => db().attendee_details.push({ attendee_ID: nextId('a'), reservation_ID: r.reservation_ID, full_name: a.full_name.trim(), contact_number: (a.contact_number || '').trim() || null, ticket_code: null, status: false, checked_in_at: null }));
    const free = total === 0;
    db().payments.push({ payment_ID: nextId('p'), reservation_ID: r.reservation_ID, amount: total, payment_method: free ? 'Free' : method, payment_status: free ? 'paid' : 'unpaid', payment_date: free ? now() : null });
    save(); return r;
  }
  const rand4 = () => Array.from({ length: 4 }, () => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'[Math.floor(Math.random() * 32)]).join('');
  function confirm(rid) {
    const r = resById(rid); if (!r) throw new Error('Reservation not found.');
    if (r.reservation_status === 'cancelled') throw new Error('A cancelled reservation cannot be confirmed.');
    if (r.reservation_status === 'confirmed') return r;
    r.reservation_status = 'confirmed';
    // Electronic tickets are generated here: one unique code (and QR) per attendee.
    attendeesOf(rid).forEach((a, i) => { if (!a.ticket_code) a.ticket_code = `TKT-${pad(rid)}-${i + 1}-${rand4()}`; });
    save(); return r;
  }
  function cancel(rid) {
    const r = resById(rid); if (!r) throw new Error('Reservation not found.');
    if (r.reservation_status === 'cancelled') return r;
    if (attendeesOf(rid).some(a => a.status)) throw new Error('Cannot cancel: an attendee has already checked in.');
    const t = typeById(r.ticket_ID); t.quantity_available += r.quantity; t.quantity_sold -= r.quantity;
    r.reservation_status = 'cancelled';
    attendeesOf(rid).forEach(a => { a.ticket_code = null; }); // ticket is voided
    save(); return r;
  }
  function markPaid(rid, method) {
    const p = paymentOf(rid); if (!p) throw new Error('Payment record not found.');
    const r = resById(rid); if (r.reservation_status === 'cancelled') throw new Error('Reservation is cancelled.');
    // Payment scope (final decision): NO online gateway. Admin records payment received (cash / bank / e-wallet).
    p.payment_status = 'paid'; p.payment_method = method || p.payment_method || 'Cash'; p.payment_date = now(); save(); return p;
  }

  /* ---------- check-in / ticket validation ---------- */
  function checkIn(code, eventId) {
    code = String(code || '').trim().toUpperCase();
    if (!code) return { level: 'danger', msg: 'Enter or scan a ticket code.' };
    const a = db().attendee_details.find(x => x.ticket_code === code);
    if (!a) return { level: 'danger', msg: 'Invalid ticket — code not found or ticket was voided.' };
    const r = resById(a.reservation_ID), v = resView(r);
    const base = { attendee: a, reservation: v };
    if (eventId && v.event.event_ID !== +eventId) return { ...base, level: 'danger', msg: `Wrong event — this ticket is for “${v.event.event_name}”.` };
    if (r.reservation_status !== 'confirmed') return { ...base, level: 'danger', msg: `Reservation is ${r.reservation_status}. Only confirmed tickets are valid.` };
    if (a.status) return { ...base, level: 'warning', msg: `Already checked in at ${new Date(a.checked_in_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}.` };
    a.status = true; a.checked_in_at = now(); save();
    return { ...base, level: 'success', msg: 'Valid ticket — attendee checked in.' };
  }
  function undoCheckIn(attendeeId) { const a = db().attendee_details.find(x => x.attendee_ID === +attendeeId); if (a) { a.status = false; a.checked_in_at = null; save(); } }

  /* ---------- events (admin) ---------- */
  function saveEvent(data, types, adminId) {
    if (!data.event_name.trim() || !data.event_date || !data.event_time || !data.venue.trim()) throw new Error('Name, date, time and venue are required.');
    if (!types.length) throw new Error('Add at least one ticket type.');
    if (types.some(t => !t.type_name.trim() || t.price < 0 || !(t.total >= 1))) throw new Error('Each ticket type needs a name, a price of 0 or more, and a capacity of at least 1.');
    let e;
    if (data.event_ID) { e = eventById(data.event_ID); Object.assign(e, data); }
    else { e = { ...data, event_ID: nextId('e'), user_ID: +adminId }; db().events.push(e); }
    const existing = typesOf(e.event_ID);
    const keep = new Set();
    for (const t of types) {
      if (t.ticket_ID) {
        const row = typeById(t.ticket_ID);
        if (t.total < row.quantity_sold) throw new Error(`${row.type_name}: capacity cannot be lower than the ${row.quantity_sold} ticket(s) already reserved.`);
        row.type_name = t.type_name.trim(); row.price = +t.price; row.quantity_available = t.total - row.quantity_sold; keep.add(row.ticket_ID);
      } else {
        const row = { ticket_ID: nextId('t'), event_ID: e.event_ID, type_name: t.type_name.trim(), price: +t.price, quantity_available: +t.total, quantity_sold: 0 };
        db().ticket_types.push(row); keep.add(row.ticket_ID);
      }
    }
    for (const row of existing) if (!keep.has(row.ticket_ID)) {
      if (row.quantity_sold > 0) throw new Error(`Cannot remove ${row.type_name}: it already has reservations.`);
      db().ticket_types = db().ticket_types.filter(x => x.ticket_ID !== row.ticket_ID);
    }
    save(); return e;
  }
  function deleteEvent(id) {
    const tIds = typesOf(id).map(t => t.ticket_ID);
    if (db().reservations.some(r => tIds.includes(r.ticket_ID) && r.reservation_status !== 'cancelled')) throw new Error('This event still has active reservations. Cancel them first.');
    const rIds = db().reservations.filter(r => tIds.includes(r.ticket_ID)).map(r => r.reservation_ID);
    const d = db();
    d.attendee_details = d.attendee_details.filter(a => !rIds.includes(a.reservation_ID));
    d.payments = d.payments.filter(p => !rIds.includes(p.reservation_ID));
    d.reservations = d.reservations.filter(r => !rIds.includes(r.reservation_ID));
    d.ticket_types = d.ticket_types.filter(t => t.event_ID !== +id);
    d.events = d.events.filter(e => e.event_ID !== +id);
    save();
  }

  /* ---------- reports ---------- */
  function report() {
    const rs = reservations().map(resView);
    const byStatus = { confirmed: 0, pending: 0, cancelled: 0 };
    rs.forEach(r => byStatus[r.reservation_status]++);
    const perEvent = events().map(e => {
      const mine = rs.filter(r => r.event.event_ID === e.event_ID && r.reservation_status !== 'cancelled');
      const s = seats(e.event_ID);
      return {
        event: e, capacity: s.total, reserved: s.sold,
        confirmed: mine.filter(r => r.reservation_status === 'confirmed').reduce((n, r) => n + r.quantity, 0),
        revenue: mine.filter(r => r.payment && r.payment.payment_status === 'paid').reduce((n, r) => n + r.total_amount, 0),
        checkedIn: mine.reduce((n, r) => n + r.checkedIn, 0)
      };
    });
    return { byStatus, perEvent, totals: { events: db().events.length, reservations: rs.length, users: db().users.length, pending: byStatus.pending } };
  }
  function reset() { localStorage.removeItem(KEY); cache = null; load(); }

  global.Store = { db, users, events, eventById, typesOf, typeById, reservations, resById, resView, resCode, attendeesOf, paymentOf, userById, seats, login, register, setRole, reserve, confirm, cancel, markPaid, checkIn, undoCheckIn, saveEvent, deleteEvent, report, reset };
})(typeof window !== 'undefined' ? window : globalThis);
