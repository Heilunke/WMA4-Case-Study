<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

abstract class Controller
{
    /** JSON response (unescaped Unicode and slashes, as the frontend expects). */
    protected function json(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function user(): User
    {
        /** @var User */
        return Auth::user();
    }

    /** The per-session CSRF token the frontend must echo in X-CSRF-Token. */
    protected function csrf(): string
    {
        return (string) session('csrf', '');
    }
}
