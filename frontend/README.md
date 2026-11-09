# E-Ticket — Frontend

WMA4 · Tarlac State University, College of Computer Studies · Group 2

Static HTML/CSS/JS pages (Bootstrap 5, Bootstrap Icons, Chart.js and a QR library from a CDN, so
the first load needs internet). They talk to the Laravel REST API in `../backend/` through
`js/store.js`.

## Run it
From the repo root (needs PHP 8.2+, Composer and MySQL/MariaDB — first-time setup in [`../backend/README.md`](../backend/README.md)):

```bash
php backend/artisan migrate:fresh --seed            # create the tables + demo data
php -S localhost:8000 -t frontend backend/router.php
```
then open <http://localhost:8000>. Opening `index.html` straight from disk no longer works: the pages need the API.

| Role | Email | Password |
|---|---|---|
| Administrator | admin@eticket.test | admin123 |
| Attendee | attendee@eticket.test | attendee123 |

The login page does not display these accounts; type them in.

To reset the sample data, run `php backend/artisan migrate:fresh --seed` again.

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
- `index.html` — three tabs: **Log in**, **Sign up** (Attendee-only registration) and **Reset password** (with an Administrator-issued code)
- `attendee/` — dashboard, browse/search events, reserve tickets, my reservations, e-ticket (QR, printable)
- `admin/` — dashboard, manage events, reservations (confirm/cancel/mark paid), **check-in**, reports (doughnut chart + CSV), users/roles and password reset codes
- Every page — **Password** button in the sidebar to change your own password. Confirmations (cancel, delete, record payment) use the app's own dialogs (`App.confirm` / `App.choose` in `js/app.js`), not the browser's pop-ups.

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
| 8–11 | Section J, member roles, timeline dates, hosting | Document items — fixed in the revised proposal (online deployment is not required; the system runs on XAMPP). |
