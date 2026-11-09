<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReservationService;
use App\Support\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Ticket validation at the entrance (Administrator). */
class CheckInController extends Controller
{
    public function __construct(private ReservationService $reservations)
    {
    }

    /** POST /checkin — {ticket_code, event_ID?} */
    public function store(Request $request): JsonResponse
    {
        return $this->json($this->reservations->checkIn($request->json('ticket_code', ''), $request->json('event_ID'), $this->user()->user_ID));
    }

    /** POST /attendees/{id}/undo-checkin */
    public function undo(string $id): Response
    {
        $this->reservations->undoCheckIn(Validator::id($id));
        return response()->noContent();
    }
}
