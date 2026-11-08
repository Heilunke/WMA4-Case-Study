# E-Ticket — Web-Based Event Reservation and Ticket Management System
WMA4 · Tarlac State University, College of Computer Studies · Group 2

This folder is the **front-end prototype (UI)** for the case study. It runs by opening `index.html`
in a browser (needs internet once for Bootstrap, Bootstrap Icons, Chart.js and the QR library from a CDN).
Data is kept in the browser's `localStorage` through `js/store.js`, which mirrors the six ERD tables
(Users, Events, Ticket_Types, Reservations, Attendee_Details, Payments). Later, replace `Store.*` calls with
the PHP/Laravel REST API (events + reservations endpoints).

## Demo accounts
| Role | Email | Password |
|---|---|---|
| Administrator | admin@eticket.test | admin123 |
| Attendee | attendee@eticket.test | attendee123 |

To reset the sample data, clear this site's localStorage (or run `Store.reset()` in the console).

## Screens
- `index.html` — Login + Attendee-only registration
- `attendee/` — dashboard, browse/search events, reserve tickets, my reservations, e-ticket (QR, printable)
- `admin/` — dashboard, manage events, reservations (confirm/cancel/mark paid), **check-in**, reports (doughnut chart + CSV), users/roles

## How the panel's comments are addressed
| # | Comment | Design decision in this UI |
|---|---|---|
| 1 | E-ticket underdeveloped | Confirming a reservation issues one **unique ticket code + QR per attendee** (`attendee/ticket.html`, printable). |
| 2 | Admin vs Organizer wording | Single role named **Administrator** everywhere. |
| 3 | Public registration = Attendee only | `Store.register()` always sets `role = attendee`; only an Administrator can promote users (`admin/users.html`). |
| 4 | Payment scope | **No online gateway.** Attendee picks pay-at-venue or transfer; the Administrator *records* payment (`Mark as paid`). Free events are auto-marked paid. |
| 5 | Inventory logic | **Pending and Confirmed hold seats**; **Cancelled releases** them. Reservations with a checked-in attendee cannot be cancelled. |
| 6 | Attendee details vs multiple tickets | Reserve form generates **one attendee row per ticket**; `Attendee_Details` has one row per ticket. |
| 7 | Check-in not designed | `admin/checkin.html`: validate by code/QR scanner; rejects invalid, voided, wrong-event, unconfirmed and already-used tickets; shows live progress; undo. |
| 8 | Section J placeholders | Not a UI item — fill from the technical plan in your report (suggested content below). |
| 9–11 | Member roles, timeline dates, hosting | Not UI items — revise in the document (timeline dates must fall after the Sept 25 submission; name the hosting, e.g. a specific PHP/MySQL host). |

## Schema additions this UI assumes (update your ERD / data dictionary)
- `Events.category VARCHAR(50)` and `Events.description TEXT` (used by filters and event pages)
- `Attendee_Details.ticket_code VARCHAR(30) UNIQUE NULL` — generated on confirmation; `NULL` when voided
- `Attendee_Details.checked_in_at DATETIME NULL` (alongside the existing `status` check-in flag)
- Keep `Ticket_Types.quantity_available` as *remaining* and `quantity_sold` as *held (pending + confirmed)*

## Suggested content for Section J (Final-Term technical plan)
- **PHP / server-side:** form processing + server validation for register, reserve, event CRUD; try/catch with user-facing errors.
- **MySQL:** the six normalized tables with FKs; transactions around reserve/cancel so inventory updates are atomic.
- **CRUD/search/filter:** events (CRUD, filter by date/category/venue), reservations (status filter + search).
- **OOP PHP:** `User`, `Event`, `TicketType`, `Reservation`, `Payment` classes; custom exceptions (e.g. `SoldOutException`).
- **Auth/RBAC:** session login, middleware by role (`admin`, `attendee`).
- **Security:** `password_hash`, prepared statements/Eloquent, CSRF tokens, validation, secure logout.
- **REST API:** `/api/events`, `/api/reservations`, `/api/checkin` returning JSON, tested in Postman.
- **Laravel/Eloquent:** models + relationships, migrations and seeders mirroring `js/store.js` seed data.
- **Reports:** totals, status doughnut, per-event summary.
- **Testing/GitHub:** Postman collection, manual test cases per role, feature branches per member.
