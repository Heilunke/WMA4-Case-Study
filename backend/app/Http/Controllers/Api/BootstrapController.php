<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReservationService;
use App\Services\SnapshotService;
use App\Support\Format;
use Illuminate\Http\JsonResponse;

/** GET /api/bootstrap — everything the logged-in user may see, in one call (role-scoped). */
class BootstrapController extends Controller
{
    public function __invoke(SnapshotService $snapshots): JsonResponse
    {
        $user = $this->user();
        return $this->json([
            'user'     => Format::user($user),
            'csrf'     => $this->csrf(),
            'settings' => ['hold_hours' => ReservationService::holdHours()],
        ] + $snapshots->forUser($user));
    }
}
