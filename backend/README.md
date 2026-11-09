# E-Ticket — Backend (PHP + MySQL REST API)

WMA4 · Tarlac State University, College of Computer Studies · Group 2

Plain PHP 8.1+ (PDO, no Composer needed) and MySQL/MariaDB. It serves the JSON API that
the `frontend/` pages call. Laravel/Eloquent remains the planned Final-Term migration; the
`Services/` classes hold all business rules so they can be moved over as-is.

## Run it

**Requirements:** PHP 8.1+ with `pdo_mysql` and `mbstring`, and MySQL 8 or MariaDB 10.4+
(XAMPP has all of this).

```bash
# 1. configure (optional – defaults are root / empty password / database "eticket" on 127.0.0.1:3306)
cp backend/.env.example backend/.env        # then edit DB_USER / DB_PASS if needed

# 2. create the database, tables and demo data  (DROPS and recreates the six tables + login_attempts!)
php backend/database/setup.php              # add --no-seed for empty tables

# 3. serve the frontend and the API on one origin
php -S localhost:8000 -t frontend backend/router.php
```

Open <http://localhost:8000> and log in:

| Role | Email | Password |
|---|---|---|
| Administrator | admin@eticket.test | admin123 |
| Attendee | attendee@eticket.test | attendee123 |

These demo passwords are for development only — change them before any real deployment.

**Under XAMPP instead:** put the repo in `htdocs/`, run `setup.php` once, and open
`http://localhost/WMA4-Case-Study/frontend/`. The pages find the API at
`/WMA4-Case-Study/backend/public/index.php/api` by themselves (set `window.ETICKET_API` before
`js/store.js` to override). Alternatively import `database/schema.sql` in phpMyAdmin, then run
`setup.php` only for the demo data.

## Tests

```bash
php backend/database/setup.php                 # fresh data (the test adds and removes its own rows)
php -S localhost:8000 -t frontend backend/router.php
node backend/tests/api-smoke.mjs http://localhost:8000     # 110 checks
```

Also import `postman/E-Ticket.postman_collection.json` into Postman for manual testing (run
*Auth → Login (admin)* first; it stores the CSRF token for the other requests).

## How the panel's comments are addressed

| # | Comment | Where / how |
|---|---|---|
| 1 | E-ticket underdeveloped | `ReservationService::confirm()` issues one unique code per attendee (`TKT-<rsv>-<n>-<8 random chars>`, stored in `attendee_details.ticket_code`, UNIQUE). `GET /api/reservations/{id}/ticket` returns it only for confirmed reservations, only to the owner or an Administrator. |
| 2 | Admin vs Organizer | One role, `admin`, called **Administrator** everywhere. |
| 3 | Public registration = Attendee only | `UserService::register()` hard-codes `role = 'attendee'`; a `role` field in the request is ignored. Only an Administrator can promote (`PUT /api/users/{id}/role`). The last Administrator cannot be demoted. |
| 4 | Payment scope | **No online gateway.** `payments` is a record: starts `unpaid` (free events: `paid`), and an Administrator marks it paid (`POST /api/reservations/{id}/payment`, stored with `recorded_by`). |
| 5 | Inventory logic | **Pending and Confirmed hold seats; Cancelled releases them.** `ticket_types.quantity_available` is remaining, `quantity_sold` is held. Every change runs in a transaction after `SELECT … FOR UPDATE` on the ticket-type row, so concurrent bookings cannot oversell (verified with parallel requests). Pending reservations still **unpaid** after `RESERVATION_HOLD_HOURS` (default 48) are cancelled automatically and their seats released; paid/free Pending ones wait for the Administrator. |
| 6 | Attendee details vs multiple tickets | Exactly one `attendee_details` row per ticket; the API rejects a reservation whose attendee list does not match `quantity`. |
| 7 | Check-in not a module | `POST /api/checkin` validates a code: invalid/voided → `invalid`, other event → `wrong_event`, unconfirmed → `not_confirmed`, repeat scan → `already_used`, otherwise admits the attendee (`checked_in_at`, `checked_in_by`). A reservation with a checked-in attendee cannot be cancelled. `undo-checkin` reverses a mistake (only for a checked-in attendee of a confirmed reservation, inside a locked transaction). |
| 8 | Section J placeholders | See *Section J* below. |

## Changes to the proposal's data dictionary (Table 4)

| Table | Change |
|---|---|
| `events` | **+** `category VARCHAR(50)`, `description TEXT`, `created_at DATETIME` |
| `ticket_types` | `UNIQUE (event_ID, type_name)`; counters are `UNSIGNED` so they can never go negative |
| `reservations` | `quantity` constrained to 1–10 |
| `attendee_details` | **+** `ticket_code VARCHAR(30) UNIQUE NULL`, `checked_in_at DATETIME`, `checked_in_by` (FK → users). The `status` flag stays as "checked in". |
| `payments` | **+** `recorded_by` (FK → users); `payment_method` widened to `VARCHAR(100)`; `UNIQUE (reservation_ID)` makes it truly one-to-one |
| `users` | **+** `session_version` (bumped on a password change to log out other sessions), `reset_code_hash`, `reset_expires_at` |
| `login_attempts` | **New supporting table** (not in the ERD): failed login / reset attempts for rate limiting; emails stored as SHA-256 |
| all | Timestamps are stored in UTC; cascading deletes keep attendee/payment rows tied to their reservation |

## API

Base path `/api`. JSON in, JSON out. Errors: `{"error":{"message":"…","code":"…"}}`.
Access: **public**, **user** (any login), **admin**, **attendee**. Every non-GET request needs the
`X-CSRF-Token` header (the token is returned by login/register/`/auth/me`/`/bootstrap`).

| Method & path | Access | Purpose |
|---|---|---|
| `POST /auth/register` | public | Create an **attendee** account and log in |
| `POST /auth/login` · `POST /auth/logout` · `GET /auth/me` | public / user | Session login (HttpOnly cookie); login is rate-limited (429) |
| `POST /auth/reset-password` | public | `{email, code, password}` — set a new password with an Administrator-issued code (rate-limited) |
| `POST /auth/change-password` | user | `{current_password, password}` — logs out the account's other sessions |
| `GET /bootstrap` | user | Everything the caller may see (admins: all + users + report; attendees: the event catalogue + only their own reservations) |
| `GET /events` | user | Event list |
| `POST /events` · `PUT /events/{id}` · `DELETE /events/{id}` | admin | Event CRUD with its ticket types (`ticket_types: [{ticket_ID?, type_name, price, total}]`) |
| `POST /reservations` | attendee | `{ticket_ID, quantity, payment_method, attendees:[{full_name, contact_number}]}` |
| `GET /reservations/{id}` | owner / admin | Reservation + attendees + payment |
| `POST /reservations/{id}/confirm` | admin | Confirm and issue e-tickets |
| `POST /reservations/{id}/cancel` | owner (pending only) / admin | Cancel, release seats, void tickets |
| `POST /reservations/{id}/payment` | admin | Record payment `{payment_method}` |
| `GET /reservations/{id}/ticket` | owner / admin | The e-tickets (confirmed only) |
| `POST /checkin` | admin | `{ticket_code, event_ID?}` |
| `POST /attendees/{id}/undo-checkin` | admin | Reverse a check-in |
| `GET /reports` | admin | Totals, status counts, per-event capacity / reserved / confirmed / checked-in / revenue |
| `GET /users` · `PUT /users/{id}/role` | admin | User list and role changes |
| `POST /users/{id}/reset-code` | admin | Issue a one-time password reset code `{code, expires_at}` to hand to the user |

## Security

- **Passwords:** `password_hash()` / `password_verify()` (bcrypt), rehash on login when needed; unknown emails take comparable time and get the same error as a wrong password.
- **SQL injection:** PDO prepared statements everywhere (native prepares, no string-built values).
- **Sessions:** HttpOnly + SameSite=Lax cookie, `session_regenerate_id()` on login, destroyed on logout. The user is re-read from the DB each request, so a demotion or deletion applies immediately. The cookie is marked `Secure` whenever the request is HTTPS (`SESSION_SECURE=auto`; set `true` in production to force it; `TRUST_PROXY=true` only behind your own reverse proxy so `X-Forwarded-Proto/For` are honoured).
- **Rate limiting:** after 5 failed logins for one email from one IP (or 50 from one IP) inside 15 minutes, `/auth/login` answers 429 even for the right password. Password-reset attempts are throttled the same way. Limits are set in `.env`.
- **Password reset:** there is no e-mail server, so an Administrator issues a 10-character one-time code (Users page → *Reset code*, valid 30 minutes, stored only as SHA-256) and hands it to the user, who sets a new password under *Reset password* on the login page. A reset or a password change logs out every other session of that account.
- **CSRF:** per-session token compared with `hash_equals` on every state-changing request.
- **RBAC:** enforced in the router per route; attendees get 404 (not 403) for other people's reservations so ids cannot be probed.
- **Validation:** server-side for every field (lengths, formats, ranges). The API stores text as typed; the frontend HTML-escapes on output.
- **Errors:** no stack traces or SQL in responses unless `APP_DEBUG=true`.

Not included yet: e-mail verification and e-mailed reset links (needs a mail server), redirecting
HTTP to HTTPS (configure that on the web server / host).

## Section J — Expected Final-Term Technical Implementation (suggested text)

| Technical area | Planned implementation |
|---|---|
| PHP / server-side processing | Front controller + router; JSON endpoints for register, login, reserve, confirm, cancel, check-in and event CRUD; server-side validation (`Support/Validator`); try/catch turning exceptions into JSON errors with the right HTTP status. |
| MySQL / relational database | Six normalized InnoDB tables with PKs/FKs, UNIQUE and CHECK constraints (`database/schema.sql`); transactions with row locks around reserve / cancel / confirm / check-in so inventory stays consistent. |
| CRUD / search / filter / sort | Events (CRUD, filter by date, category, venue/name); reservations (status filter + search by code, attendee, event); users (role management). |
| Object-oriented PHP | `UserService`, `EventService`, `ReservationService`, `SnapshotService`, `ReportService`; `Router`, `Request`, `Response`, `Database`, `Auth`; exception hierarchy `HttpException → ValidationException / UnauthorizedException / ForbiddenException / NotFoundException / ConflictException → SoldOutException`. |
| Authentication / RBAC | Session login; roles `admin` and `attendee`; route-level access levels; attendees only ever receive their own reservations. |
| Security | bcrypt hashing, prepared statements, CSRF tokens, validation, session regeneration, secure logout (see above). |
| RESTful API | `/api/events`, `/api/reservations`, `/api/checkin`, `/api/reports`, `/api/users` returning JSON; Postman collection in `backend/postman/`. |
| Laravel / Eloquent | Port the services to controllers + Eloquent models (`User`, `Event`, `TicketType`, `Reservation`, `AttendeeDetail`, `Payment`) with migrations mirroring `schema.sql` and seeders mirroring `database/Seeder.php`. |
| Reports / analytics | Totals, reservation-status doughnut, tickets reserved vs capacity, per-event paid revenue and check-in counts (`ReportService`, SQL aggregates). |
| Testing / GitHub | `tests/api-smoke.mjs` (110 assertions) and the Postman collection; feature branch per member, pull requests into `main`. |

## Layout

```
backend/
  public/index.php      front controller (+ .htaccess for Apache)
  router.php            router script for `php -S`
  src/
    bootstrap.php       autoloader, config, timezone
    routes.php          the API surface
    Core/               Config, Database, Request, Response, Router, exceptions
    Support/            Auth (session + CSRF), Validator, Format (row → JSON)
    Services/           User, Event, Reservation, Snapshot, Report  ← business rules
  database/             schema.sql, setup.php, Seeder.php
  tests/api-smoke.mjs   end-to-end API tests
  postman/              Postman collection
```
