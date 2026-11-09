<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Services\SnapshotService;
use App\Services\UserService;
use App\Support\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Reports and user management (Administrator). */
class AdminController extends Controller
{
    public function __construct(private UserService $users)
    {
    }

    /** GET /reports */
    public function reports(ReportService $reports): JsonResponse
    {
        return $this->json($reports->build());
    }

    /** GET /users */
    public function users(SnapshotService $snapshots): JsonResponse
    {
        return $this->json(['users' => $snapshots->forUser($this->user())['users']]);
    }

    /** PUT /users/{id}/role — {role}; the last Administrator cannot be demoted. */
    public function setRole(Request $request, string $id): JsonResponse
    {
        return $this->json(['user' => $this->users->setRole(Validator::id($id), $request->json('role'))]);
    }

    /** POST /users/{id}/reset-code — one-time password reset code to hand to the user. */
    public function resetCode(string $id): JsonResponse
    {
        return $this->json($this->users->issueResetCode(Validator::id($id)));
    }
}
