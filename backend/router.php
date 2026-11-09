<?php
declare(strict_types=1);

/**
 * Router script for PHP's built-in dev server. Run from the repo root:
 *
 *   php -S localhost:8000 -t frontend backend/router.php
 *
 * The frontend/ folder is the web root (static pages); anything under /api goes to the API,
 * so the pages and the API share one origin (no CORS needed).
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/api' || str_starts_with($path, '/api/')) {
    require __DIR__ . '/public/index.php';
    return true;
}
return false; // let the built-in server serve the static file from frontend/
