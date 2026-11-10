<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\ReservationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * This runs on every /api request. It:
 *  - rejects requests that are too big (413) or have broken JSON (400),
 *  - cancels the unpaid Pending reservations that are past 48 hours (if this fails, the request still continues),
 *  - adds security headers so the browser doesn't guess the content type or cache the data.
 */
class ApiRequest
{
    private const MAX_BODY_BYTES = 200_000;

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $raw = $request->getContent();
            if (strlen($raw) > self::MAX_BODY_BYTES) {
                throw new ApiException(413, 'Request body is too large.', 'payload_too_large');
            }
            if (trim($raw) !== '' && !is_array(json_decode($raw, true))) {
                throw new ApiException(400, 'The request body must be valid JSON.', 'bad_json');
            }
        }

        try {
            app(ReservationService::class)->expireStale();
        } catch (\Throwable $e) {
            Log::warning('[eticket] expiry failed: ' . $e->getMessage());
        }

        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }
}
