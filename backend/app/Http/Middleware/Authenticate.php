<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks who can use a route (our RBAC):
 *   eticket.auth           = any logged-in user
 *   eticket.auth:admin     = admin only
 *   eticket.auth:attendee  = attendee only
 * The user is loaded from the database on every request, so if the admin changes someone's role
 * it works right away. If the password was changed on another device, this session gets logged out.
 */
class Authenticate
{
    public function handle(Request $request, Closure $next, ?string $role = null): Response
    {
        $user = Auth::user();
        if ($user && (int) $request->session()->get('sv') !== (int) $user->session_version) {
            Auth::logout();
            $request->session()->invalidate();
            $user = null;
        }
        if (!$user) {
            throw ApiException::unauthorized();
        }
        if ($role !== null && $user->role !== $role) {
            throw ApiException::forbidden();
        }
        return $next($request);
    }
}
