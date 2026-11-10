<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AttendeeDetail;
use App\Models\Event;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\TicketType;
use App\Models\User;
use App\Support\Format;

/**
 * Reads data in the shapes the front end uses, scoped by role:
 *   Administrator – everything.
 *   Attendee      – the whole event catalogue but ONLY their own reservations, attendees,
 *                   payments and user row.
 */
class SnapshotService
{
    public function forUser(User $user): array
    {
        $admin = $user->isAdmin();
        // For attendees every reservation-related query is narrowed to their own reservations.
        $mine = fn ($q) => $admin ? $q : $q->whereIn('reservation_ID', Reservation::where('user_ID', $user->user_ID)->select('reservation_ID'));

        $data = [
            'users'            => ($admin ? User::orderBy('user_ID')->get() : collect([$user]))->map(fn ($u) => Format::user($u))->all(),
            'events'           => Event::orderBy('event_date')->orderBy('event_time')->orderBy('event_ID')->get()->map(fn ($e) => Format::event($e))->all(),
            'ticket_types'     => TicketType::orderBy('ticket_ID')->get()->map(fn ($t) => Format::ticketType($t))->all(),
            'reservations'     => $mine(Reservation::query())->orderBy('reservation_ID')->get()->map(fn ($r) => Format::reservation($r))->all(),
            'attendee_details' => $mine(AttendeeDetail::query())->orderBy('attendee_ID')->get()->map(fn ($a) => Format::attendee($a))->all(),
            'payments'         => $mine(Payment::query())->orderBy('payment_ID')->get()->map(fn ($p) => Format::payment($p))->all(),
        ];
        if ($admin) {
            $data['report'] = (new ReportService())->build();
        }
        return $data;
    }

    /**
     * GET /api/reservations — newest first, joined with event, ticket type, payment, booker and attendees.
     * Administrators get every reservation; attendees only their own.
     * Optional filters: status (pending | confirmed | cancelled) and q (searches RSV code, booker, event, attendee names).
     */
    public function reservationList(User $user, array $filters): array
    {
        $query = Reservation::with(['user', 'ticketType.event', 'payment', 'attendees'])->orderByDesc('reservation_ID');
        if (!$user->isAdmin()) {
            $query->where('user_ID', $user->user_ID);
        }
        $status = (string) ($filters['status'] ?? '');
        if ($status !== '') {
            if (!in_array($status, ['pending', 'confirmed', 'cancelled'], true)) {
                throw ApiException::validation('Status must be pending, confirmed or cancelled.');
            }
            $query->where('reservation_status', $status);
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $id = preg_match('/^(?:RSV-)?0*(\d+)$/i', $q, $m) ? (int) $m[1] : null; // "RSV-003" or "3"
            $query->where(function ($w) use ($like, $id) {
                $w->whereHas('user', fn ($u) => $u->where('full_Name', 'like', $like))
                    ->orWhereHas('ticketType.event', fn ($e) => $e->where('event_name', 'like', $like))
                    ->orWhereHas('attendees', fn ($a) => $a->where('full_name', 'like', $like));
                if ($id !== null) {
                    $w->orWhere('reservation_ID', $id);
                }
            });
        }
        return $query->get()->map(fn (Reservation $r) => [
            'reservation' => Format::reservation($r),
            'code'        => sprintf('RSV-%03d', $r->reservation_ID),
            'event'       => Format::event($r->ticketType->event),
            'ticket_type' => Format::ticketType($r->ticketType),
            'payment'     => $r->payment ? Format::payment($r->payment) : null,
            'user'        => Format::user($r->user),
            'attendees'   => $r->attendees->map(fn ($a) => Format::attendee($a))->all(),
            'checked_in'  => $r->attendees->where('status', 1)->count(),
        ])->all();
    }

    /** One reservation with its attendees, payment, ticket type and event — via Eloquent relationships. */
    public function reservationFor(int $id, User $user): array
    {
        $r = Reservation::with(['attendees', 'payment', 'ticketType.event'])->find($id);
        // Same error for "missing" and "someone else's" so ids cannot be probed.
        if (!$r || (!$user->isAdmin() && (int) $r->user_ID !== (int) $user->user_ID)) {
            throw ApiException::notFound('Reservation not found.');
        }
        return [
            'reservation' => Format::reservation($r),
            'attendees'   => $r->attendees->map(fn ($a) => Format::attendee($a))->all(),
            'payment'     => $r->payment ? Format::payment($r->payment) : null,
            'ticket_type' => Format::ticketType($r->ticketType),
            'event'       => Format::event($r->ticketType->event),
        ];
    }
}
