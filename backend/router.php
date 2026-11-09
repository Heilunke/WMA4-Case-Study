<?php

/**
 * Router script for PHP's built-in server: serves the frontend/ pages and sends /api/* to Laravel.
 * Run from the repo root:
 *
 *   php -S localhost:8000 -t frontend backend/router.php
 *
 * The pages and the API share one origin, so the session cookie works without CORS.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/api' || str_starts_with($path, '/api/') || $path === '/up') {
    // Make Laravel see itself as served from its own public/index.php at the web root.
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/public/index.php';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';
    chdir(__DIR__ . '/public');
    require __DIR__ . '/public/index.php';
    return true;
}
return false; // static file from frontend/
