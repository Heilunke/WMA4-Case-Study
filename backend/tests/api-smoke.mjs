// End-to-end API test. Needs the server running AND a freshly seeded database:
//   php backend/artisan migrate:fresh --seed && php backend/artisan cache:clear
//   php -S localhost:8000 -t frontend backend/router.php
//   node backend/tests/api-smoke.mjs [http://localhost:8000]
// It creates and deletes its own data on top of the seed, so reseed before running it again.

const BASE = (process.argv[2] || 'http://localhost:8000') + '/api';
let pass = 0, fail = 0;

class Client {
  cookie = ''; csrf = '';
  async call(method, path, body, { csrf = true } = {}) {
    const headers = { 'Content-Type': 'application/json' };
    if (this.cookie) headers.Cookie = this.cookie;
    if (csrf && this.csrf) headers['X-CSRF-Token'] = this.csrf;
    const res = await fetch(BASE + path, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
    for (const c of res.headers.getSetCookie?.() ?? []) this.cookie = c.split(';')[0];
    const text = await res.text();
    const json = text ? JSON.parse(text) : null;
    if (json?.csrf) this.csrf = json.csrf;
    return { status: res.status, json };
  }
  async login(email, password) { const r = await this.call('POST', '/auth/login', { email, password }); if (r.status !== 200) throw new Error('login failed ' + email); return r; }
}

function check(name, cond, extra = '') {
  if (cond) { pass++; console.log('  ok   ' + name); }
  else { fail++; console.log('  FAIL ' + name + (extra ? '  -> ' + extra : '')); }
}
const eq = (name, got, want) => check(name, JSON.stringify(got) === JSON.stringify(want), `got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`);
const section = t => console.log('\n' + t);

const anon = new Client(), admin = new Client(), maria = new Client(), juan = new Client();
const day = n => { const d = new Date(Date.now() + n * 864e5); return d.toISOString().slice(0, 10); };

section('Authentication');
eq('bootstrap without login -> 401', (await anon.call('GET', '/bootstrap')).status, 401);
eq('wrong password -> 401', (await anon.call('POST', '/auth/login', { email: 'admin@eticket.test', password: 'nope' })).status, 401);
eq('unknown email -> 401', (await anon.call('POST', '/auth/login', { email: 'ghost@x.test', password: 'whatever1' })).status, 401);
let r = await admin.login('admin@eticket.test', 'admin123');
eq('admin login -> role admin', r.json.user.role, 'admin');
check('login response has no password hash', !JSON.stringify(r.json).includes('password'));
await maria.login('attendee@eticket.test', 'attendee123');
await juan.login('juan@eticket.test', 'attendee123');

section('Registration is attendee-only');
const evil = new Client();
r = await evil.call('POST', '/auth/register', { full_Name: 'Eve', email: 'eve@test.test', password: 'secret1', role: 'admin' });
eq('register ignores role=admin', [r.status, r.json.user.role], [201, 'attendee']);
eq('duplicate email -> 409', (await new Client().call('POST', '/auth/register', { full_Name: 'Eve2', email: 'EVE@test.test', password: 'secret1' })).status, 409);
eq('short password -> 422', (await new Client().call('POST', '/auth/register', { full_Name: 'X', email: 'x@test.test', password: '123' })).status, 422);
eq('bad email -> 422', (await new Client().call('POST', '/auth/register', { full_Name: 'X', email: 'not-an-email', password: 'secret1' })).status, 422);
eq('new attendee cannot create an event (RBAC)', (await evil.call('POST', '/events', {})).status, 403);

section('Role-scoped data');
const adm = (await admin.call('GET', '/bootstrap')).json, mar = (await maria.call('GET', '/bootstrap')).json;
check('admin sees all reservations', adm.reservations.length === 5, adm.reservations.length);
check('admin gets users + report', adm.users.length >= 4 && !!adm.report);
check('attendee sees only own reservations', mar.reservations.length === 2 && mar.reservations.every(x => x.user_ID === mar.user.user_ID), mar.reservations.length);
check('attendee sees only own attendees/payments', mar.attendee_details.length === 3 && mar.payments.length === 2);
check('attendee sees only own user row, no report', mar.users.length === 1 && !mar.report);
check('attendee still sees the full event catalogue', mar.events.length === 4 && mar.ticket_types.length === 6);
eq('attendee cannot list users', (await maria.call('GET', '/users')).status, 403);
eq('attendee cannot read admin report', (await maria.call('GET', '/reports')).status, 403);
const otherRes = adm.reservations.find(x => x.user_ID !== mar.user.user_ID);
eq("attendee cannot read someone else's reservation (404)", (await maria.call('GET', '/reservations/' + otherRes.reservation_ID)).status, 404);

section('CSRF');
const ws = adm.events.find(e => e.event_name.startsWith('Web Dev'));
const wsTicket = adm.ticket_types.find(t => t.event_ID === ws.event_ID);
const payload = (ticket, n, names) => ({ ticket_ID: ticket.ticket_ID, quantity: n, payment_method: 'Pay at venue (cash)', attendees: names.map(x => ({ full_name: x, contact_number: '0917 123 4567' })) });
r = await maria.call('POST', '/reservations', payload(wsTicket, 1, ['Maria']), { csrf: false });
eq('POST without CSRF token -> 403', r.status, 403);
r = await maria.call('POST', '/reservations', payload(wsTicket, 1, ['Maria']));
eq('POST with CSRF token -> 201', r.status, 201);
const wsRes = r.json.reservation;
eq('new reservation is pending', wsRes.reservation_status, 'pending');
eq('payment starts unpaid (cost 100)', [r.json.payment.payment_status, r.json.payment.amount], ['unpaid', 100]);
eq('attendee row has no ticket code yet', r.json.attendees[0].ticket_code, null);

section('Inventory: pending holds seats');
let snap = (await admin.call('GET', '/bootstrap')).json;
let t = snap.ticket_types.find(x => x.ticket_ID === wsTicket.ticket_ID);
eq('available 29 / sold 1 after reserving', [t.quantity_available, t.quantity_sold], [29, 1]);
r = await maria.call('POST', '/reservations', payload(wsTicket, 30, Array(30).fill('X')));
eq('quantity above the 10-per-reservation limit -> 422', r.status, 422);
// drain the workshop: 29 left. 3 x 10 is too many for 29, so use 10 + 10 + 9.
for (const n of [10, 10, 9]) eq(`reserve ${n}`, (await juan.call('POST', '/reservations', payload(wsTicket, n, Array(n).fill('Guest')))).status, 201);
r = await maria.call('POST', '/reservations', payload(wsTicket, 1, ['Late']));
eq('sold out -> 409 sold_out', [r.status, r.json.error.code], [409, 'sold_out']);
snap = (await admin.call('GET', '/bootstrap')).json;
t = snap.ticket_types.find(x => x.ticket_ID === wsTicket.ticket_ID);
eq('workshop available 0 / sold 30', [t.quantity_available, t.quantity_sold], [0, 30]);
const juanRes = snap.reservations.filter(x => x.ticket_ID === wsTicket.ticket_ID && x.user_ID !== wsRes.user_ID);
r = await maria.call('POST', '/reservations', payload(wsTicket, 2, ['Only one name']));
eq('attendee names must match quantity -> 422', r.status, 422);

section('Cancel rules and seat release');
eq("attendee cannot cancel someone else's reservation (404)", (await maria.call('POST', `/reservations/${juanRes[0].reservation_ID}/cancel`)).status, 404);
r = await juan.call('POST', `/reservations/${juanRes[0].reservation_ID}/cancel`);
eq('attendee cancels own pending -> cancelled', r.json.reservation.reservation_status, 'cancelled');
snap = (await admin.call('GET', '/bootstrap')).json;
t = snap.ticket_types.find(x => x.ticket_ID === wsTicket.ticket_ID);
eq('10 seats released (available 10 / sold 20)', [t.quantity_available, t.quantity_sold], [10, 20]);
eq('cancel is idempotent', (await juan.call('POST', `/reservations/${juanRes[0].reservation_ID}/cancel`)).status, 200);
snap = (await admin.call('GET', '/bootstrap')).json;
t = snap.ticket_types.find(x => x.ticket_ID === wsTicket.ticket_ID);
eq('second cancel did not release twice', [t.quantity_available, t.quantity_sold], [10, 20]);

section('Confirm issues e-tickets');
eq('attendee cannot confirm (RBAC)', (await maria.call('POST', `/reservations/${wsRes.reservation_ID}/confirm`)).status, 403);
eq('e-ticket of a pending reservation -> 409', (await maria.call('GET', `/reservations/${wsRes.reservation_ID}/ticket`)).status, 409);
r = await admin.call('POST', `/reservations/${wsRes.reservation_ID}/confirm`);
eq('admin confirms', r.json.reservation.reservation_status, 'confirmed');
const code = r.json.attendees[0].ticket_code;
check('ticket code format TKT-<id>-<n>-<8 chars>', /^TKT-\d{3}-1-[A-Z2-9]{8}$/.test(code), code);
r = await maria.call('GET', `/reservations/${wsRes.reservation_ID}/ticket`);
eq('owner can read the e-ticket', [r.status, r.json.attendees[0].ticket_code], [200, code]);
eq('another attendee cannot read it (404)', (await juan.call('GET', `/reservations/${wsRes.reservation_ID}/ticket`)).status, 404);
eq('attendee cannot cancel a confirmed reservation (403)', (await maria.call('POST', `/reservations/${wsRes.reservation_ID}/cancel`)).status, 403);
const code2 = (await admin.call('POST', `/reservations/${wsRes.reservation_ID}/confirm`)).json.attendees[0].ticket_code;
eq('confirm is idempotent (code unchanged)', code2, code);
eq('cancelled reservation cannot be confirmed -> 409', (await admin.call('POST', `/reservations/${juanRes[0].reservation_ID}/confirm`)).status, 409);

section('Payment (recorded, no gateway)');
r = await admin.call('POST', `/reservations/${wsRes.reservation_ID}/payment`, { payment_method: 'GCash' });
eq('admin marks paid', [r.json.payment.payment_status, r.json.payment.payment_method], ['paid', 'GCash']);
eq('attendee cannot mark paid (403)', (await maria.call('POST', `/reservations/${wsRes.reservation_ID}/payment`, { payment_method: 'Cash' })).status, 403);
eq('cannot record payment on a cancelled reservation -> 409', (await admin.call('POST', `/reservations/${juanRes[0].reservation_ID}/payment`, { payment_method: 'Cash' })).status, 409);

section('Check-in');
eq('attendee cannot check in (403)', (await maria.call('POST', '/checkin', { ticket_code: code })).status, 403);
eq('empty code -> danger', (await admin.call('POST', '/checkin', { ticket_code: '' })).json.level, 'danger');
eq('unknown code -> invalid', (await admin.call('POST', '/checkin', { ticket_code: 'TKT-999-9-ZZZZZZZZ' })).json.code, 'invalid');
const funEvent = snap.events.find(e => e.event_name.startsWith('Fun Run'));
eq('ticket for another event -> wrong_event', (await admin.call('POST', '/checkin', { ticket_code: code, event_ID: funEvent.event_ID })).json.code, 'wrong_event');
const pendingFun = snap.reservations.find(x => x.reservation_status === 'pending' && snap.ticket_types.find(tt => tt.ticket_ID === x.ticket_ID).event_ID === funEvent.event_ID);
r = await admin.call('POST', '/checkin', { ticket_code: code.toLowerCase(), event_ID: ws.event_ID });
eq('valid ticket (lower-case input) -> success', [r.json.level, r.json.code], ['success', 'ok']);
r = await admin.call('POST', '/checkin', { ticket_code: code, event_ID: ws.event_ID });
eq('second scan -> already_used (warning)', [r.json.level, r.json.code], ['warning', 'already_used']);
r = await admin.call('POST', `/reservations/${wsRes.reservation_ID}/cancel`);
eq('cannot cancel once someone checked in -> 409', r.status, 409);
const attId = (await admin.call('GET', '/bootstrap')).json.attendee_details.find(a => a.ticket_code === code).attendee_ID;
eq('undo check-in', (await admin.call('POST', `/attendees/${attId}/undo-checkin`)).status, 204);
eq('can scan again after undo', (await admin.call('POST', '/checkin', { ticket_code: code })).json.code, 'ok');
await admin.call('POST', `/attendees/${attId}/undo-checkin`);
r = await admin.call('POST', `/attendees/${attId}/undo-checkin`);
eq('undo on an attendee who is not checked in -> 409 not_checked_in', [r.status, r.json.error.code], [409, 'not_checked_in']);
eq('undo on an unknown attendee -> 404', (await admin.call('POST', '/attendees/999999/undo-checkin')).status, 404);

section('Event management');
eq('create event with bad date -> 422', (await admin.call('POST', '/events', { event_name: 'X', event_date: '2026-13-45', event_time: '10:00', venue: 'V', ticket_types: [{ type_name: 'A', price: 0, total: 5 }] })).status, 422);
eq('create event with no ticket types -> 422', (await admin.call('POST', '/events', { event_name: 'X', event_date: day(5), event_time: '10:00', venue: 'V', ticket_types: [] })).status, 422);
eq('duplicate ticket type names -> 422', (await admin.call('POST', '/events', { event_name: 'X', event_date: day(5), event_time: '10:00', venue: 'V', ticket_types: [{ type_name: 'A', price: 0, total: 5 }, { type_name: 'a', price: 0, total: 5 }] })).status, 422);
r = await admin.call('POST', '/events', { event_name: '<script>alert(1)</script> Test Night', category: 'Concert', event_date: day(5), event_time: '19:30', venue: 'Hall', description: 'Fun', ticket_types: [{ type_name: 'GA', price: 99.5, total: 5 }] });
eq('create event -> 201', r.status, 201);
const newId = r.json.event_ID;
snap = (await admin.call('GET', '/bootstrap')).json;
const created = snap.events.find(e => e.event_ID === newId);
eq('event stored with time trimmed to HH:MM', created.event_time, '19:30');
check('HTML in names is stored as plain text (front end escapes on render)', created.event_name.startsWith('<script>'));
const newTicket = snap.ticket_types.find(x => x.event_ID === newId);
eq('ticket type price/capacity', [newTicket.price, newTicket.quantity_available, newTicket.quantity_sold], [99.5, 5, 0]);
r = await maria.call('POST', '/reservations', payload(newTicket, 3, ['A', 'B', 'C']));
eq('reserve 3 of 5', r.status, 201);
r = await admin.call('PUT', '/events/' + newId, { ...created, ticket_types: [{ ticket_ID: newTicket.ticket_ID, type_name: 'GA', price: 99.5, total: 2 }] });
eq('capacity below reserved seats -> 409', r.status, 409);
r = await admin.call('PUT', '/events/' + newId, { ...created, venue: 'Bigger Hall', ticket_types: [{ ticket_ID: newTicket.ticket_ID, type_name: 'General', price: 120, total: 10 }] });
eq('edit event + raise capacity', r.status, 200);
snap = (await admin.call('GET', '/bootstrap')).json;
const edited = snap.ticket_types.find(x => x.ticket_ID === newTicket.ticket_ID);
eq('available recomputed = capacity - held (10 - 3)', [edited.type_name, edited.price, edited.quantity_available, edited.quantity_sold], ['General', 120, 7, 3]);
r = await admin.call('PUT', '/events/' + newId, { ...created, ticket_types: [{ type_name: 'VIP', price: 5, total: 5 }] });
eq('cannot drop a ticket type that has reservations -> 409', r.status, 409);
eq('cannot delete an event with active reservations -> 409', (await admin.call('DELETE', '/events/' + newId)).status, 409);
const newRes = snap.reservations.find(x => x.ticket_ID === newTicket.ticket_ID);
await maria.call('POST', `/reservations/${newRes.reservation_ID}/cancel`);
eq('delete event once its reservations are cancelled -> 204', (await admin.call('DELETE', '/events/' + newId)).status, 204);
snap = (await admin.call('GET', '/bootstrap')).json;
check('event, ticket types and reservation are gone', !snap.events.some(e => e.event_ID === newId) && !snap.ticket_types.some(x => x.event_ID === newId) && !snap.reservations.some(x => x.reservation_ID === newRes.reservation_ID));
eq('attendee cannot delete events (403)', (await maria.call('DELETE', '/events/1')).status, 403);
eq('unknown event -> 404', (await admin.call('DELETE', '/events/99999')).status, 404);

section('Past events are closed for reservation');
r = await admin.call('POST', '/events', { event_name: 'Old Event', event_date: day(-3), event_time: '10:00', venue: 'V', ticket_types: [{ type_name: 'A', price: 0, total: 5 }] });
snap = (await admin.call('GET', '/bootstrap')).json;
const oldT = snap.ticket_types.find(x => x.event_ID === r.json.event_ID);
r = await maria.call('POST', '/reservations', payload(oldT, 1, ['A']));
eq('reserving a past event -> 409 event_closed', [r.status, r.json.error.code], [409, 'event_closed']);

section('Free events are auto-paid');
const freeT = snap.ticket_types.find(x => x.event_ID === funEvent.event_ID);
r = await maria.call('POST', '/reservations', payload(freeT, 1, ['Maria']));
eq('free reservation: amount 0, payment paid, method Free', [r.json.payment.amount, r.json.payment.payment_status, r.json.payment.payment_method], [0, 'paid', 'Free']);

section('Users and roles');
const uid = snap.users.find(u => u.email === 'eve@test.test').user_ID;
eq('attendee cannot change roles (403)', (await maria.call('PUT', `/users/${uid}/role`, { role: 'admin' })).status, 403);
eq('invalid role -> 422', (await admin.call('PUT', `/users/${uid}/role`, { role: 'superuser' })).status, 422);
eq('admin promotes a user', (await admin.call('PUT', `/users/${uid}/role`, { role: 'admin' })).json.user.role, 'admin');
eq('promoted user can now reach admin data', (await evil.call('GET', '/reports')).status, 200);
eq('demote back', (await admin.call('PUT', `/users/${uid}/role`, { role: 'attendee' })).json.user.role, 'attendee');
eq('demoted user loses admin access immediately', (await evil.call('GET', '/reports')).status, 403);
const adminId = snap.users.find(u => u.email === 'admin@eticket.test').user_ID;
eq('last administrator cannot be demoted -> 422', (await admin.call('PUT', `/users/${adminId}/role`, { role: 'attendee' })).status, 422);

section('Password reset (Administrator-issued code)');
eq('attendee cannot issue a reset code (403)', (await maria.call('POST', `/users/${uid}/reset-code`)).status, 403);
r = await admin.call('POST', `/users/${uid}/reset-code`);
const resetCode = r.json.code;
check('admin gets a 10-character one-time code with an expiry', r.status === 200 && /^[A-Z2-9]{10}$/.test(resetCode) && !!r.json.expires_at, JSON.stringify(r.json));
const eve2 = new Client(); await eve2.login('eve@test.test', 'secret1'); // a second session for Eve
r = await anon.call('POST', '/auth/reset-password', { email: 'eve@test.test', code: 'WRONGCODE2', password: 'newpass1' });
eq('wrong reset code -> 422', r.status, 422);
r = await anon.call('POST', '/auth/reset-password', { email: 'eve@test.test', code: resetCode.toLowerCase(), password: 'newpass1' });
eq('correct reset code (any case) -> 204', r.status, 204);
eq("Eve's existing sessions are logged out", [(await evil.call('GET', '/bootstrap')).status, (await eve2.call('GET', '/bootstrap')).status], [401, 401]);
eq('reset code works only once -> 422', (await anon.call('POST', '/auth/reset-password', { email: 'eve@test.test', code: resetCode, password: 'other123' })).status, 422);
eq('old password no longer works', (await new Client().call('POST', '/auth/login', { email: 'eve@test.test', password: 'secret1' })).status, 401);
await evil.login('eve@test.test', 'newpass1');
check('login with the new password works', true);

section('Change password');
await eve2.login('eve@test.test', 'newpass1');
eq('wrong current password -> 422', (await evil.call('POST', '/auth/change-password', { current_password: 'nope', password: 'brandnew1' })).status, 422);
eq('too-short new password -> 422', (await evil.call('POST', '/auth/change-password', { current_password: 'newpass1', password: '123' })).status, 422);
eq('change password -> 204', (await evil.call('POST', '/auth/change-password', { current_password: 'newpass1', password: 'brandnew1' })).status, 204);
eq('this session stays logged in', (await evil.call('GET', '/bootstrap')).status, 200);
eq('the other session is logged out', (await eve2.call('GET', '/bootstrap')).status, 401);
eq('not logged in -> 401', (await new Client().call('POST', '/auth/change-password', { current_password: 'x', password: 'yyyyyy' })).status, 401);

section('Login rate limiting');
await new Client().call('POST', '/auth/register', { full_Name: 'Locky', email: 'locky@test.test', password: 'secret1' });
const tries = [];
for (let i = 0; i < 5; i++) tries.push((await anon.call('POST', '/auth/login', { email: 'locky@test.test', password: 'wrong' + i })).status);
eq('5 wrong passwords -> 401 each', tries, [401, 401, 401, 401, 401]);
r = await anon.call('POST', '/auth/login', { email: 'locky@test.test', password: 'secret1' });
eq('6th attempt is refused even with the right password -> 429', [r.status, r.json.error.code], [429, 'too_many_attempts']);
eq('other accounts are not locked', (await new Client().call('POST', '/auth/login', { email: 'admin@eticket.test', password: 'admin123' })).status, 200);

section('Reports');
r = (await admin.call('GET', '/reports')).json;
eq('status totals add up', r.totals.reservations, r.byStatus.confirmed + r.byStatus.pending + r.byStatus.cancelled);
const wsRow = r.perEvent.find(p => p.event.event_ID === ws.event_ID);
eq('workshop row: capacity 30, reserved 20, confirmed 1, revenue 100', [wsRow.capacity, wsRow.reserved, wsRow.confirmed, wsRow.revenue], [30, 20, 1, 100]);

section('Sessions');
eq('logout -> 204', (await maria.call('POST', '/auth/logout')).status, 204);
eq('session is dead after logout', (await maria.call('GET', '/bootstrap')).status, 401);
eq('unknown endpoint -> 404', (await admin.call('GET', '/nope')).status, 404);
eq('wrong method -> 405', (await admin.call('PATCH', '/events')).status, 405);
{
  const res = await fetch(BASE + '/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{not json' });
  eq('malformed JSON -> 400', res.status, 400);
}

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
