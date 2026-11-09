<?php
declare(strict_types=1);

/**
 * Front controller – every /api/... request lands here.
 * Apache: backend/public/.htaccess rewrites to this file.
 * PHP dev server: backend/router.php includes it.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use ETicket\Core\Config;
use ETicket\Core\HttpException;
use ETicket\Core\Request;
use ETicket\Core\Response;

try {
    // Release seats held by unpaid Pending reservations past the hold window (see ReservationService).
    // Never let housekeeping break the actual request.
    try {
        (new ETicket\Services\ReservationService())->expireStale();
    } catch (Throwable $e) {
        error_log('[eticket] expiry failed: ' . $e->getMessage());
    }
    $router = require BASE_PATH . '/src/routes.php';
    $response = $router->dispatch(Request::capture());
} catch (HttpException $e) {
    $response = Response::error($e);
} catch (PDOException $e) {
    error_log('[eticket] database error: ' . $e->getMessage());
    $response = new Response(['error' => [
        'message' => Config::debug() ? $e->getMessage() : 'A database error occurred. Please try again.',
        'code'    => 'database_error',
    ]], 500);
} catch (Throwable $e) {
    error_log('[eticket] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $response = new Response(['error' => [
        'message' => Config::debug() ? $e->getMessage() : 'Something went wrong on the server.',
        'code'    => 'server_error',
    ]], 500);
}
$response->send();
