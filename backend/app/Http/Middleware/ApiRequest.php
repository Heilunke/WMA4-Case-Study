<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\ReservationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every /api request:
 *  - rejects oversized or malformed JSON bodies (413 / 400),
 *  - releases seats of unpaid Pending reservations past the hold window (never breaks the request),
 *  - adds no-sniff / no-store headers to every response.
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
