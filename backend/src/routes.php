<?php
declare(strict_types=1);

/**
 * The REST API. Access levels: public | user (any login) | admin | attendee.
 * Every response is JSON; errors look like {"error":{"message":"…","code":"…"}}.
 */

use ETicket\Core\Request;
use ETicket\Core\Response;
use ETicket\Core\Router;
use ETicket\Services\EventService;
use ETicket\Services\ReportService;
use ETicket\Services\ReservationService;
use ETicket\Services\SnapshotService;
use ETicket\Services\UserService;
use ETicket\Support\Auth;
use ETicket\Support\Validator;

$router = new Router();
$users = new UserService();
$events = new EventService();
$reservations = new ReservationService();
$snapshots = new SnapshotService();

$id = static fn (array $p, string $key = 'id'): int => Validator::int($p[$key] ?? null, 'id', 1, PHP_INT_MAX);

/* ---------------- auth ---------------- */
$router->add('POST', '/auth/register', function (Request $r) use ($users) {
    $user = $users->register($r->body);   // always role = attendee
    Auth::login($user);
    return new Response(['user' => $user, 'csrf' => Auth::csrfToken()], 201);
}, 'public');

$router->add('POST', '/auth/login', function (Request $r) use ($users) {
    $user = $users->authenticate($r->body['email'] ?? null, $r->body['password'] ?? null, Request::clientIp());
    Auth::login($user);
    return ['user' => $user, 'csrf' => Auth::csrfToken()];
}, 'public');

/* Password reset without e-mail: an Administrator issues a one-time code (POST /users/{id}/reset-code)
   and gives it to the user, who sets a new password here. Rate-limited like login. */
$router->add('POST', '/auth/reset-password', function (Request $r) use ($users) {
    $users->resetPassword($r->body, Request::clientIp());
    return new Response(null, 204);
}, 'public');

$router->add('POST', '/auth/change-password', function (Request $r) use ($users) {
    $users->changePassword(Auth::user()['user_ID'], $r->body);
    Auth::refreshSessionVersion(); // stay logged in here; every other session of this account is logged out
    return new Response(null, 204);
});

$router->add('POST', '/auth/logout', function () {
    Auth::logout();
    return new Response(null, 204);
}, 'public');

$router->add('GET', '/auth/me', fn () => ['user' => Auth::user(), 'csrf' => Auth::csrfToken()]);

/* ---------------- snapshot used by the front end ---------------- */
$router->add('GET', '/bootstrap', function () use ($snapshots) {
    $user = Auth::user();
    return ['user' => $user, 'csrf' => Auth::csrfToken(), 'settings' => ['hold_hours' => ReservationService::holdHours()]]
        + $snapshots->forUser($user);
});

/* ---------------- events ---------------- */
$router->add('GET', '/events', fn () => ['events' => $snapshots->forUser(Auth::user())['events']]);

$router->add('POST', '/events', function (Request $r) use ($events) {
    return new Response(['event_ID' => $events->save(null, $r->body, Auth::user()['user_ID'])], 201);
}, 'admin');

$router->add('PUT', '/events/{id}', function (Request $r, array $p) use ($events, $id) {
    return ['event_ID' => $events->save($id($p), $r->body, Auth::user()['user_ID'])];
}, 'admin');

$router->add('DELETE', '/events/{id}', function (Request $r, array $p) use ($events, $id) {
    $events->delete($id($p));
    return new Response(null, 204);
}, 'admin');

/* ---------------- reservations ---------------- */
$router->add('POST', '/reservations', function (Request $r) use ($reservations, $snapshots) {
    $user = Auth::user();
    $rid = $reservations->reserve($user['user_ID'], $r->body);
    return new Response($snapshots->reservationFor($rid, $user), 201);
}, 'attendee');

$router->add('GET', '/reservations/{id}', fn (Request $r, array $p) => $snapshots->reservationFor($id($p), Auth::user()));

$router->add('POST', '/reservations/{id}/confirm', function (Request $r, array $p) use ($reservations, $snapshots, $id) {
    $reservations->confirm($id($p));
    return $snapshots->reservationFor($id($p), Auth::user());
}, 'admin');

$router->add('POST', '/reservations/{id}/cancel', function (Request $r, array $p) use ($reservations, $snapshots, $id) {
    $user = Auth::user();
    $reservations->cancel($id($p), $user);
    return $snapshots->reservationFor($id($p), $user);
});

$router->add('POST', '/reservations/{id}/payment', function (Request $r, array $p) use ($reservations, $snapshots, $id) {
    $reservations->markPaid($id($p), $r->body['payment_method'] ?? 'Cash', Auth::user()['user_ID']);
    return $snapshots->reservationFor($id($p), Auth::user());
}, 'admin');

/* The e-ticket: only for CONFIRMED reservations, only to their owner (or an Administrator). */
$router->add('GET', '/reservations/{id}/ticket', function (Request $r, array $p) use ($snapshots, $id) {
    $view = $snapshots->reservationFor($id($p), Auth::user());
    if ($view['reservation']['reservation_status'] !== 'confirmed') {
        throw new \ETicket\Core\ConflictException('E-tickets are issued only after an Administrator confirms the reservation.', 'not_confirmed');
    }
    return $view;
});

/* ---------------- check-in ---------------- */
$router->add('POST', '/checkin', function (Request $r) use ($reservations) {
    return $reservations->checkIn($r->body['ticket_code'] ?? '', $r->body['event_ID'] ?? null, Auth::user()['user_ID']);
}, 'admin');

$router->add('POST', '/attendees/{id}/undo-checkin', function (Request $r, array $p) use ($reservations, $id) {
    $reservations->undoCheckIn($id($p));
    return new Response(null, 204);
}, 'admin');

/* ---------------- reports & users (admin) ---------------- */
$router->add('GET', '/reports', fn () => (new ReportService())->build(), 'admin');

$router->add('GET', '/users', fn () => ['users' => $snapshots->forUser(Auth::user())['users']], 'admin');

$router->add('PUT', '/users/{id}/role', function (Request $r, array $p) use ($users, $id) {
    return ['user' => $users->setRole($id($p), $r->body['role'] ?? null)];
}, 'admin');

$router->add('POST', '/users/{id}/reset-code', function (Request $r, array $p) use ($users, $id) {
    return $users->issueResetCode($id($p));
}, 'admin');

return $router;
