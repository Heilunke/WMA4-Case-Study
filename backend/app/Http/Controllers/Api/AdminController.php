<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Services\SnapshotService;
use App\Services\UserService;
use App\Support\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin only: reports and managing the users. */
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

    /** PUT /users/{id}/role - change a user's role. We don't allow removing the last admin. */
    public function setRole(Request $request, string $id): JsonResponse
    {
        return $this->json(['user' => $this->users->setRole(Validator::id($id), $request->json('role'))]);
    }

    /** POST /users/{id}/reset-code - makes a one-time reset code that the admin gives to the user. */
    public function resetCode(string $id): JsonResponse
    {
        return $this->json($this->users->issueResetCode(Validator::id($id)));
    }
}
