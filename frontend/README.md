# E-Ticket — Frontend

WMA4 · Tarlac State University, College of Computer Studies · Group 2

Static HTML/CSS/JS pages (Bootstrap 5, Bootstrap Icons, Chart.js and a QR library from a CDN, so
the first load needs internet). They talk to the PHP/MySQL REST API in `../backend/` through
`js/store.js`.

## Run it
From the repo root (needs PHP 8.1+ and MySQL/MariaDB — full steps in [`../backend/README.md`](../backend/README.md)):

```bash
php backend/database/setup.php                      # create the database + demo data
php -S localhost:8000 -t frontend backend/router.php
```
then open <http://localhost:8000>. Opening `index.html` straight from disk no longer works: the pages need the API.

| Role | Email | Password |
|---|---|---|
| Administrator | admin@eticket.test | admin123 |
| Attendee | attendee@eticket.test | attendee123 |

To reset the sample data, run `php backend/database/setup.php` again.

## How it is connected to the backend
- `js/store.js` is the only file that calls the API. It loads a role-scoped snapshot with `GET /api/bootstrap`
  (`await Store.init()`, done by `App.shell()` on every page); reads are synchronous against that snapshot;
  every change (`reserve`, `confirm`, `cancel`, `checkIn`, `saveEvent`, …) is an awaited API call followed by a refresh,
  so the screen always reflects the database.
- Business rules (inventory, ticket codes, RBAC, validation) are enforced by the server. The UI only shows the server's messages.
- Login is a server session (HttpOnly cookie) with a CSRF token sent in `X-CSRF-Token`. The role kept in `sessionStorage`
  is only used for fast redirects and is overwritten by what the server says on every page load.
- Pages are `<script type="module">` so they can `await App.shell(...)`.
- API location: `/api` when served by `php -S` as above; under XAMPP (`…/frontend/…`) it is
  `…/backend/public/index.php/api`, detected automatically. Override with `window.ETICKET_API` before `js/store.js`.

## Screens
- `index.html` — Login + Attendee-only registration
- `attendee/` — dashboard, browse/search events, reserve tickets, my reservations, e-ticket (QR, printable)
- `admin/` — dashboard, manage events, reservations (confirm/cancel/mark paid), **check-in**, reports (doughnut chart + CSV), users/roles

## How the panel's comments are addressed
| # | Comment | Design decision |
|---|---|---|
| 1 | E-ticket underdeveloped | Confirming a reservation issues one **unique ticket code + QR per attendee** (`attendee/ticket.html`, printable). |
| 2 | Admin vs Organizer wording | Single role named **Administrator** everywhere. |
| 3 | Public registration = Attendee only | The API forces `role = attendee` on sign-up; only an Administrator can promote users (`admin/users.html`). |
| 4 | Payment scope | **No online gateway.** The attendee picks pay-at-venue or transfer; the Administrator *records* payment (`Mark as paid`). Free events are auto-marked paid. |
| 5 | Inventory logic | **Pending and Confirmed hold seats**; **Cancelled releases** them. Reservations with a checked-in attendee cannot be cancelled. |
| 6 | Attendee details vs multiple tickets | The reserve form has **one attendee row per ticket**, and `attendee_details` has one row per ticket. |
| 7 | Check-in not designed | `admin/checkin.html`: validate by code/QR scanner; rejects invalid, voided, wrong-event, unconfirmed and already-used tickets; shows live progress; undo. |
| 8 | Section J placeholders | Suggested text is in [`../backend/README.md`](../backend/README.md#section-j--expected-final-term-technical-implementation-suggested-text). |
| 9–11 | Member roles, timeline dates, hosting | Document items — fix in the report (timeline dates must fall after the Sept 25 submission; name the hosting, e.g. a specific PHP/MySQL host). |
