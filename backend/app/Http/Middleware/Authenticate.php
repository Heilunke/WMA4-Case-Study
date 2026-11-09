<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route access levels (RBAC):  ->middleware('eticket.auth')         any logged-in user
 *                              ->middleware('eticket.auth:admin')   Administrators only
 *                              ->middleware('eticket.auth:attendee') attendees only
 * The user is re-read from the database on every request (Laravel's session guard), so a demotion
 * applies immediately; a password change elsewhere (session_version bumped) logs this session out.
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
