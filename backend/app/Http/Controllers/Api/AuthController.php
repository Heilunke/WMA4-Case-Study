<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserService;
use App\Support\Format;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/** /api/auth/* - sign up, login, logout, and password reset/change. */
class AuthController extends Controller
{
    public function __construct(private UserService $users)
    {
    }

    /** POST /auth/register (no login needed) - always makes an attendee account, then logs them in. */
    public function register(Request $request): JsonResponse
    {
        $user = $this->users->register($request->json()->all());
        $this->startSession($request, User::find($user['user_ID']));
        return $this->json(['user' => $user, 'csrf' => $this->csrf()], 201);
    }

    /** POST /auth/login (no login needed, has a limit on wrong tries). */
    public function login(Request $request): JsonResponse
    {
        $user = $this->users->authenticate($request->json('email'), $request->json('password'), $request->ip());
        $this->startSession($request, $user);
        return $this->json(['user' => Format::user($user), 'csrf' => $this->csrf()]);
    }

    /** POST /auth/logout */
    public function logout(Request $request): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        return response()->noContent();
    }

    /** GET /auth/me */
    public function me(): JsonResponse
    {
        return $this->json(['user' => Format::user($this->user()), 'csrf' => $this->csrf()]);
    }

    /** POST /auth/reset-password (no login needed) - uses the email, the reset code and the new password. */
    public function resetPassword(Request $request): Response
    {
        $this->users->resetPassword($request->json()->all(), $request->ip());
        return response()->noContent();
    }

    /** POST /auth/change-password - needs the current password. This session stays logged in. */
    public function changePassword(Request $request): Response
    {
        $this->users->changePassword($this->user()->user_ID, $request->json()->all());
        $request->session()->put('sv', (int) User::find($this->user()->user_ID)->session_version);
        return response()->noContent();
    }

    /** Logs the user in: new session id (for security), saves the session version and makes a new CSRF token. */
    private function startSession(Request $request, User $user): void
    {
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('sv', (int) User::find($user->user_ID)->session_version);
        $request->session()->put('csrf', bin2hex(random_bytes(32)));
    }
}
