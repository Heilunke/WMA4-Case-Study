<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSRF protection. Every request that changes something (POST, PUT, DELETE) must send the session's
 * token in the X-CSRF-Token header. We give the token after login, and js/store.js sends it automatically.
 */
class VerifyCsrfHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $expected = (string) $request->session()->get('csrf', '');
            $sent = (string) $request->header('X-CSRF-Token', '');
            if ($expected === '' || !hash_equals($expected, $sent)) {
                throw ApiException::forbidden('Your session token is missing or expired. Reload the page and try again.');
            }
        }
        return $next($request);
    }
}
