<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

abstract class Controller
{
    /** Sends back a JSON response. We keep the ₱ sign and slashes readable instead of escaped. */
    protected function json(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function user(): User
    {
        /** @var User */
        return Auth::user();
    }

    /** The CSRF token of this session. The frontend sends it back in the X-CSRF-Token header. */
    protected function csrf(): string
    {
        return (string) session('csrf', '');
    }
}
