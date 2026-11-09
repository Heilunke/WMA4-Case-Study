# E-Ticket — Web-Based Event Reservation and Ticket Management System

WMA4 · Advanced Web Development · Tarlac State University, College of Computer Studies · Group 2

| Folder | What it is |
|---|---|
| [`frontend/`](frontend/README.md) | Browser UI (HTML/CSS/JS, Bootstrap) |
| [`backend/`](backend/README.md) | Laravel 12 REST API + MySQL: Eloquent models, migrations, seeders, API tests, Postman |
| `e2e/` | Playwright browser tests + one-command test runner |
| `context/` | Proposal and the panel's comments |

## Quick start
Needs PHP 8.2+, Composer and MySQL/MariaDB (XAMPP). Create a database named `eticket` first.
```bash
cd backend
composer install && cp .env.example .env && php artisan key:generate   # first time only
php artisan migrate:fresh --seed                                       # tables + demo data
cd ..
php -S localhost:8000 -t frontend backend/router.php
```
Open <http://localhost:8000> — Administrator `admin@eticket.test` / `admin123`, Attendee `attendee@eticket.test` / `attendee123`.
