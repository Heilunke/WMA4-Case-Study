# E-Ticket — Web-Based Event Reservation and Ticket Management System

WMA4 · Advanced Web Development · Tarlac State University, College of Computer Studies · Group 2

| Folder | What it is |
|---|---|
| [`frontend/`](frontend/README.md) | Browser UI (HTML/CSS/JS, Bootstrap) |
| [`backend/`](backend/README.md) | PHP + MySQL REST API, database schema, seed data, tests |
| `context/` | Proposal and the panel's comments |

## Quick start
```bash
php backend/database/setup.php                      # create the database + demo data (see backend/README.md for DB settings)
php -S localhost:8000 -t frontend backend/router.php
```
Open <http://localhost:8000> — Administrator `admin@eticket.test` / `admin123`, Attendee `attendee@eticket.test` / `attendee123`.
