<?php

/**
 * Router for PHP's built-in server. It shows the pages from frontend/ and sends the /api requests to Laravel.
 * Run this from the main project folder:
 *
 *   php -S localhost:8000 -t frontend backend/router.php
 *
 * The pages and the API use the same address, so the login cookie works and we don't need CORS.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/api' || str_starts_with($path, '/api/') || $path === '/up') {
    // We set these so Laravel thinks it's running from its own public/index.php.
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/public/index.php';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';
    chdir(__DIR__ . '/public');
    require __DIR__ . '/public/index.php';
    return true;
}
return false; // not an API request, so just show the file from frontend/
