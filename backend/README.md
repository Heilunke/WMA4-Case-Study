# E-Ticket — Backend (Laravel 12 REST API + MySQL)

WMA4 · Tarlac State University, College of Computer Studies · Group 2

A **Laravel 12** JSON API with **Eloquent models, relationships, migrations and seeders**, serving the
pages in [`../frontend`](../frontend). Business rules live in service classes (`app/Services/`), called by
thin controllers (`app/Http/Controllers/Api/`).

## Run it

**Requirements:** PHP 8.2+ with `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`; MySQL 8 or MariaDB 10.4+
(XAMPP has all of this); [Composer](https://getcomposer.org).

```bash
cd backend
composer install                      # first time only (vendor/ is not in git)
cp .env.example .env                  # first time only; edit DB_USERNAME / DB_PASSWORD if needed
php artisan key:generate              # first time only

# create the "eticket" database first (phpMyAdmin → New, or: CREATE DATABASE eticket), then:
php artisan migrate:fresh --seed      # DROPS and recreates the tables + demo data

cd ..
php -S localhost:8000 -t frontend backend/router.php
```

Open <http://localhost:8000> and log in:

| Role          | Email                 | Password    |
| ------------- | --------------------- | ----------- |
| Administrator | admin@eticket.test    | admin123    |
| Attendee      | attendee@eticket.test | attendee123 |

These demo passwords are for development only.

**Under XAMPP instead:** after the steps above, open `http://localhost/WMA4-Case-Study/frontend/`.
The pages find the API at `/WMA4-Case-Study/backend/public/index.php/api` by themselves (set
`window.ETICKET_API` before `js/store.js` to override).

To reset the demo data: `php artisan migrate:fresh --seed` then `php artisan cache:clear` (clears login
rate-limit counters).

## Tests

The API smoke test needs fresh demo data and a running server:

```bash
php backend/artisan migrate:fresh --seed && php backend/artisan cache:clear
php -S localhost:8000 -t frontend backend/router.php      # in a second terminal
node backend/tests/api-smoke.mjs                          # must end with: 110 passed, 0 failed
```

The test creates its own data, so reseed before running it again.

Also import [`postman/E-Ticket.postman_collection.json`](postman/E-Ticket.postman_collection.json) into
Postman (run *Auth → Login (admin)* first; it stores the CSRF token). The *Password reset & change*
folder is self-contained.

## Where things are (what to show in the defence)

| Concept | File(s) |
|---|---|
| **Migrations** (schema, keys, FKs, CHECK constraints) | `database/migrations/2026_11_09_00000{1..6}_create_*_table.php` |
| **Eloquent models + relationships** | `app/Models/` — `User`, `Event`, `TicketType`, `Reservation`, `AttendeeDetail`, `Payment` |
| **Seeder** (demo data through the real services) | `database/seeders/DatabaseSeeder.php` |
| **Routes** (access level on each group) | `routes/api.php` |
| **Controllers** (MVC: thin, call the services) | `app/Http/Controllers/Api/` |
| **Business rules** (inventory, e-tickets, check-in, expiry, accounts) | `app/Services/` — `ReservationService`, `EventService`, `UserService`, `ReportService`, `SnapshotService` |
| **Middleware** (login + roles, CSRF header, JSON checks, expiry) | `app/Http/Middleware/` — `Authenticate`, `VerifyCsrfHeader`, `ApiRequest` |
| **JSON errors** `{"error":{"message","code"}}` | `bootstrap/app.php` (`withExceptions`), `app/Exceptions/ApiException.php` |
| **Validation** | `app/Support/Validator.php` |
| **Settings** (hold hours, login limits, reset-code lifetime) | `config/eticket.php` ← `.env` |
| **Dev-server router** (frontend + `/api` on one origin) | `router.php` |

### Relationships

```
User           hasMany    Reservation           (the attendee who booked)
User           hasMany    Event                 (createdEvents: the Administrator who created it)
Event          belongsTo  User (creator)        · hasMany TicketType · hasManyThrough Reservation
TicketType     belongsTo  Event                 · hasMany Reservation
Reservation    belongsTo  User, TicketType      · hasMany AttendeeDetail · hasOne Payment
AttendeeDetail belongsTo  Reservation           · belongsTo User (checkedInBy)
Payment        belongsTo  Reservation           · belongsTo User (recordedBy)
```

Example: `Reservation::with(['attendees', 'payment', 'ticketType.event'])->find($id)` loads a
reservation with everything the e-ticket page needs (`app/Services/SnapshotService.php`).

### How a request flows

`frontend/js/store.js` → `fetch('/api/reservations', …)` → `routes/api.php` → middleware
(`ApiRequest` → `Authenticate` → `VerifyCsrfHeader`) → `ReservationController@store` →
`ReservationService::reserve()` (`DB::transaction` + `lockForUpdate` on the ticket type) → Eloquent →
MySQL → JSON back to the page.

## How the panel's comments are addressed

| # | Comment | Where / how |
|---|---|---|
| 1 | E-ticket underdeveloped | `ReservationService::confirm()` issues one unique code per attendee (`TKT-<rsv>-<n>-<8 random chars>`, `attendee_details.ticket_code`, UNIQUE). `GET /api/reservations/{id}/ticket` returns it only for confirmed reservations, only to the owner or an Administrator. The page draws one QR code per ticket. |
| 2 | Admin vs Organizer | One role, `admin`, called **Administrator** everywhere. |
| 3 | Public registration = Attendee only | `UserService::register()` hard-codes `role = 'attendee'`; a `role` field in the request is ignored. Only an Administrator can promote (`PUT /api/users/{id}/role`); the last Administrator cannot be demoted. |
| 4 | Payment scope | **No online gateway.** `payments` is a record: starts `unpaid` (free events: `paid`); an Administrator records it (`POST /api/reservations/{id}/payment`, stored with `recorded_by`). |
| 5 | Inventory logic | **Pending and Confirmed hold seats; Cancelled releases them.** `quantity_available` = remaining, `quantity_sold` = held. Every change runs in `DB::transaction()` after `lockForUpdate()` on the ticket-type row, so concurrent bookings cannot oversell. Pending reservations still **unpaid** after `RESERVATION_HOLD_HOURS` (48) are cancelled automatically. |
| 6 | Attendee details vs multiple tickets | Exactly one `attendee_details` row per ticket; a reservation whose attendee list does not match `quantity` is rejected. |
| 7 | Check-in not a module | `POST /api/checkin` validates a code: `invalid` / `wrong_event` / `not_confirmed` / `already_used`, otherwise admits the attendee (`checked_in_at`, `checked_in_by`). A reservation with a checked-in attendee cannot be cancelled; `undo-checkin` reverses a mistake. |

## Changes to the proposal's data dictionary (Table 4)

| Table | Change |
|---|---|
| `events` | **+** `category VARCHAR(50)`, `description TEXT`, `created_at DATETIME` |
| `ticket_types` | `UNIQUE (event_ID, type_name)`; counters are `UNSIGNED` so they can never go negative; `CHECK (price >= 0)` |
| `reservations` | `CHECK (quantity BETWEEN 1 AND 10)` |
| `attendee_details` | **+** `ticket_code VARCHAR(30) UNIQUE NULL`, `checked_in_at DATETIME`, `checked_in_by` (FK → users) |
| `payments` | **+** `recorded_by` (FK → users); `payment_method` `VARCHAR(100)`; `UNIQUE (reservation_ID)` makes it one-to-one |
| `users` | **+** `session_version` (bumped on a password change to log out other sessions), `reset_code_hash`, `reset_expires_at` |
| all | Timestamps stored in UTC; cascading deletes keep attendee/payment rows tied to their reservation |

## API

Base path `/api`. JSON in, JSON out. Errors: `{"error":{"message":"…","code":"…"}}`.
Every non-GET request on a protected route needs the `X-CSRF-Token` header (returned by
login / register / `/auth/me` / `/bootstrap`).

| Method & path | Access | Purpose |
|---|---|---|
| `POST /auth/register` | public | Create an **attendee** account and log in |
| `POST /auth/login` · `POST /auth/logout` · `GET /auth/me` | public / user | Session login (HttpOnly cookie); login is rate-limited (429) |
| `POST /auth/reset-password` | public | `{email, code, password}` with an Administrator-issued code (rate-limited) |
| `POST /auth/change-password` | user | `{current_password, password}`; logs out the account's other sessions |
| `GET /bootstrap` | user | Everything the caller may see (admins: all + users + report; attendees: catalogue + own reservations) |
| `GET /events` | user | Event list |
| `POST /events` · `PUT /events/{id}` · `DELETE /events/{id}` | admin | Event CRUD with ticket types `[{ticket_ID?, type_name, price, total}]` |
| `POST /reservations` | attendee | `{ticket_ID, quantity, payment_method, attendees:[{full_name, contact_number}]}` |
| `GET /reservations/{id}` | owner / admin | Reservation + attendees + payment |
| `POST /reservations/{id}/confirm` | admin | Confirm and issue e-tickets |
| `POST /reservations/{id}/cancel` | owner (pending) / admin | Cancel, release seats, void tickets |
| `POST /reservations/{id}/payment` | admin | Record payment `{payment_method}` |
| `GET /reservations/{id}/ticket` | owner / admin | The e-tickets (confirmed only) |
| `POST /checkin` · `POST /attendees/{id}/undo-checkin` | admin | Validate a ticket code `{ticket_code, event_ID?}` · reverse a check-in |
| `GET /reports` | admin | Totals, status counts, per-event capacity / reserved / confirmed / checked-in / revenue |
| `GET /users` · `PUT /users/{id}/role` · `POST /users/{id}/reset-code` | admin | Users, role changes, one-time reset code |

## Security

- **Passwords:** `Hash::make()` / `Hash::check()` (bcrypt), rehash on login when needed; unknown emails take comparable time and get the same error.
- **SQL injection:** Eloquent / query builder with bound parameters everywhere.
- **Sessions:** Laravel session guard, HttpOnly cookie, new session id at login, invalidated at logout; the user is re-read every request, so a demotion applies immediately.
- **CSRF:** per-session token in the `X-CSRF-Token` header, compared with `hash_equals` (`VerifyCsrfHeader`).
- **RBAC:** route middleware `eticket.auth`, `eticket.auth:admin`, `eticket.auth:attendee`; attendees get 404 (not 403) for other people's reservations so ids cannot be probed.
- **Rate limiting:** Laravel `RateLimiter` — 5 failed logins per email+IP (or 50 per IP) in 15 minutes → 429 even for the right password; password-reset attempts likewise.
- **Password reset:** no e-mail server, so an Administrator issues a 10-character one-time code (valid 30 min, stored only as SHA-256). A reset or password change logs out the account's other sessions.
- **Validation:** server-side for every field (`app/Support/Validator.php`); the frontend HTML-escapes on output.
- **Errors:** no stack traces or SQL in responses unless `APP_DEBUG=true`.

Not included: e-mail verification / e-mailed reset links (needs a mail server); HTTP→HTTPS redirect
(configure on the web server). Online deployment is not required for the Final; the system runs on XAMPP.

## Layout

```
backend/
  app/
    Models/                Eloquent models + relationships
    Services/              business rules (reservation, event, user, report, snapshot)
    Http/Controllers/Api/  thin controllers
    Http/Middleware/       Authenticate (login + role), VerifyCsrfHeader, ApiRequest
    Exceptions/            ApiException
    Support/               Format (model → JSON), Validator
  bootstrap/app.php        middleware group, aliases, JSON error rendering
  config/eticket.php       business settings
  database/migrations/     the six tables
  database/seeders/        demo data
  routes/api.php           the API surface
  public/index.php         Laravel entry point (Apache / XAMPP)
  router.php               `php -S` router: frontend + /api on one origin
  tests/api-smoke.mjs      end-to-end API test (110 checks)
  postman/                 Postman collection
```
