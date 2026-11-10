<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\ReservationService;
use App\Services\SnapshotService;
use App\Support\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/reservations — reserve, view, confirm, cancel, record payment, e-ticket. */
class ReservationController extends Controller
{
    public function __construct(private ReservationService $reservations, private SnapshotService $snapshots)
    {
    }

    /** GET /reservations?status=&q= — admins: all; attendees: only their own. */
    public function index(Request $request): JsonResponse
    {
        return $this->json(['reservations' => $this->snapshots->reservationList($this->user(), $request->query())]);
    }

    /** POST /reservations (attendee) */
    public function store(Request $request): JsonResponse
    {
        $rid = $this->reservations->reserve($this->user()->user_ID, $request->json()->all());
        return $this->json($this->snapshots->reservationFor($rid, $this->user()), 201);
    }

    /** GET /reservations/{id} (owner or Administrator) */
    public function show(string $id): JsonResponse
    {
        return $this->json($this->snapshots->reservationFor(Validator::id($id), $this->user()));
    }

    /** POST /reservations/{id}/confirm (Administrator) — issues the e-tickets. */
    public function confirm(string $id): JsonResponse
    {
        $this->reservations->confirm(Validator::id($id));
        return $this->show($id);
    }

    /** POST /reservations/{id}/cancel (owner while pending, or Administrator) */
    public function cancel(string $id): JsonResponse
    {
        $this->reservations->cancel(Validator::id($id), $this->user());
        return $this->show($id);
    }

    /** POST /reservations/{id}/payment (Administrator) — {payment_method} */
    public function payment(Request $request, string $id): JsonResponse
    {
        $this->reservations->markPaid(Validator::id($id), $request->json('payment_method', 'Cash'), $this->user()->user_ID);
        return $this->show($id);
    }

    /** GET /reservations/{id}/ticket — only for CONFIRMED reservations, only to the owner or an Administrator. */
    public function ticket(string $id): JsonResponse
    {
        $view = $this->snapshots->reservationFor(Validator::id($id), $this->user());
        if ($view['reservation']['reservation_status'] !== 'confirmed') {
            throw ApiException::conflict('E-tickets are issued only after an Administrator confirms the reservation.', 'not_confirmed');
        }
        return $this->json($view);
    }
}
