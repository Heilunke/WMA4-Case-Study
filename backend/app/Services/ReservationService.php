<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AttendeeDetail;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\TicketType;
use App\Models\User;
use App\Support\Format;
use App\Support\Validator;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * This is where most of the reservation rules are: reserving, confirming, cancelling,
 * recording payments, check-in and issuing the e-tickets.
 *
 * Seat rule (from the panel's comment): Pending and Confirmed reservations both hold their seats,
 * meaning quantity_available goes down as soon as the attendee submits. Cancelling gives the seats back.
 * Every time we change the seats we use DB::transaction() and lockForUpdate() on the ticket type row
 * (SELECT ... FOR UPDATE). This way two people can't buy the same last ticket at the same time.
 */
class ReservationService
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // we removed I, O, 0 and 1 because they look alike

    /** @return int reservation_ID */
    public function reserve(int $userId, array $in): int
    {
        $ticketId = Validator::int($in['ticket_ID'] ?? null, 'Ticket type', 1, PHP_INT_MAX);
        $qty = Validator::int($in['quantity'] ?? null, 'Quantity', 1, config('eticket.max_per_reservation'));
        $method = Validator::string($in['payment_method'] ?? null, 'Payment method', 1, 100);
        $attendees = $this->validateAttendees($in['attendees'] ?? null, $qty);

        return DB::transaction(function () use ($userId, $ticketId, $qty, $method, $attendees) {
            // Lock the ticket type row first. If two people reserve at the same time, the second one waits here.
            $ticket = TicketType::lockForUpdate()->find($ticketId) ?? throw ApiException::notFound('Ticket type not found.');
            $event = $ticket->event;

            $starts = new DateTimeImmutable($event->event_date . ' ' . $event->event_time, new DateTimeZone(config('eticket.event_tz')));
            if ($starts <= new DateTimeImmutable('now')) {
                throw ApiException::conflict('This event has already started, so it can no longer be reserved.', 'event_closed');
            }

            $left = (int) $ticket->quantity_available;
            if ($qty > $left) {
                throw ApiException::soldOut($left > 0 ? "Only $left ticket(s) left for {$ticket->type_name}." : "{$ticket->type_name} is sold out.");
            }
            TicketType::whereKey($ticketId)->update([
                'quantity_available' => DB::raw('quantity_available - ' . $qty),
                'quantity_sold'      => DB::raw('quantity_sold + ' . $qty),
            ]);

            $total = round((float) $ticket->price * $qty, 2);
            $now = Format::nowUtc();
            $reservation = Reservation::create([
                'user_ID'            => $userId,
                'ticket_ID'          => $ticketId,
                'quantity'           => $qty,
                'total_amount'       => $total,
                'reservation_status' => 'pending',
                'reservation_date'   => $now,
            ]);
            // One attendee row for every ticket (from the panel's comment).
            $reservation->attendees()->createMany($attendees);

            // No online payment, we only keep a record of it. Free events are automatically marked as paid.
            $free = $total == 0.0;
            $reservation->payment()->create([
                'amount'         => $total,
                'payment_method' => $free ? 'Free' : $method,
                'payment_status' => $free ? 'paid' : 'unpaid',
                'payment_date'   => $free ? $now : null,
            ]);
            return (int) $reservation->reservation_ID;
        });
    }

    private function validateAttendees(mixed $raw, int $qty): array
    {
        if (!is_array($raw) || count($raw) !== $qty) {
            throw ApiException::validation('Enter the name of every attendee (one per ticket).');
        }
        $out = [];
        foreach (array_values($raw) as $a) {
            if (!is_array($a)) {
                throw ApiException::validation('Enter the name of every attendee (one per ticket).');
            }
            $name = Validator::string($a['full_name'] ?? null, 'Attendee name', 1, 100);
            $contact = Validator::optionalString($a['contact_number'] ?? null, 'Contact number', 20);
            if ($contact !== '' && !preg_match('/^[0-9+()\-\s]{3,20}$/', $contact)) {
                throw ApiException::validation('Contact number may only contain digits, spaces, + ( ) and -.');
            }
            $out[] = ['full_name' => $name, 'contact_number' => $contact === '' ? null : $contact];
        }
        return $out;
    }

    /** Confirms a pending reservation and gives every attendee their own unique ticket code. */
    public function confirm(int $reservationId): void
    {
        DB::transaction(function () use ($reservationId) {
            $r = $this->lockReservation($reservationId);
            if ($r->reservation_status === 'cancelled') {
                throw ApiException::conflict('A cancelled reservation cannot be confirmed.');
            }
            if ($r->reservation_status === 'confirmed') {
                return;
            }
            $r->update(['reservation_status' => 'confirmed']);
            $attendees = AttendeeDetail::where('reservation_ID', $reservationId)->orderBy('attendee_ID')->lockForUpdate()->get();
            foreach ($attendees as $i => $a) {
                if ($a->ticket_code === null) {
                    $a->update(['ticket_code' => $this->newTicketCode($reservationId, $i + 1)]);
                }
            }
        });
    }

    /** Makes a code like TKT-005-1-K7QM4XRP. If the code somehow already exists, we just make a new one. */
    private function newTicketCode(int $reservationId, int $n): string
    {
        do {
            $rand = '';
            for ($i = 0; $i < 8; $i++) {
                $rand .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
            $code = sprintf('TKT-%03d-%d-%s', $reservationId, $n, $rand);
        } while (AttendeeDetail::where('ticket_code', $code)->exists());
        return $code;
    }

    /**
     * Cancels a reservation, gives back the seats and removes the ticket codes.
     * Attendees can only cancel their own reservations while they are still Pending.
     * The admin can cancel any reservation as long as nobody has checked in yet.
     */
    public function cancel(int $reservationId, User $actor): void
    {
        DB::transaction(function () use ($reservationId, $actor) {
            $r = $this->lockReservation($reservationId);
            if (!$actor->isAdmin()) {
                if ((int) $r->user_ID !== (int) $actor->user_ID) {
                    throw ApiException::notFound('Reservation not found.'); // we say "not found" so they can't tell it belongs to someone else
                }
                if ($r->reservation_status === 'confirmed') {
                    throw ApiException::forbidden('A confirmed reservation can only be cancelled by an Administrator.');
                }
            }
            if ($r->reservation_status === 'cancelled') {
                return;
            }
            if ($r->attendees()->where('status', 1)->exists()) {
                throw ApiException::conflict('Cannot cancel: an attendee has already checked in.');
            }
            $this->release($r);
        });
    }

    /** Does the actual cancelling (the reservation must already be locked): seats back, codes removed. */
    private function release(Reservation $r): void
    {
        // We always lock the reservation first and then the ticket type, so two requests won't get stuck waiting on each other.
        $qty = (int) $r->quantity;
        TicketType::lockForUpdate()->find($r->ticket_ID);
        TicketType::whereKey($r->ticket_ID)->update([
            'quantity_available' => DB::raw('quantity_available + ' . $qty),
            'quantity_sold'      => DB::raw('quantity_sold - ' . $qty),
        ]);
        $r->update(['reservation_status' => 'cancelled']);
        AttendeeDetail::where('reservation_ID', $r->reservation_ID)->update(['ticket_code' => null]); // the tickets can't be used anymore
    }

    public static function holdHours(): int
    {
        return max(0, config('eticket.hold_hours'));
    }

    /**
     * Auto-cancel: if a Pending reservation is still unpaid after the hold time (48 hours by default),
     * we cancel it and give the seats back. Paid ones are left alone so the admin can still confirm them.
     */
    public function expireStale(): int
    {
        $hours = self::holdHours();
        if ($hours === 0) {
            return 0;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - $hours * 3600);
        $ids = Reservation::where('reservation_status', 'pending')
            ->where('reservation_date', '<', $cutoff)
            ->whereHas('payment', fn ($p) => $p->where('payment_status', 'unpaid'))
            ->limit(50)->pluck('reservation_ID');
        $expired = 0;
        foreach ($ids as $rid) {
            $expired += DB::transaction(function () use ($rid, $cutoff) {
                $r = $this->lockReservation((int) $rid);
                $paid = Payment::where('reservation_ID', $rid)->lockForUpdate()->value('payment_status');
                if ($r->reservation_status !== 'pending' || $paid !== 'unpaid' || $r->reservation_date >= $cutoff) {
                    return 0; // the admin already confirmed it or recorded the payment, so we skip it
                }
                $this->release($r);
                return 1;
            });
        }
        return $expired;
    }

    /** The admin records that the payment was received (cash, bank transfer, GCash, etc.). */
    public function markPaid(int $reservationId, mixed $method, int $adminId): void
    {
        $method = Validator::string($method ?? 'Cash', 'Payment method', 1, 100);
        DB::transaction(function () use ($reservationId, $method, $adminId) {
            $r = $this->lockReservation($reservationId);
            if ($r->reservation_status === 'cancelled') {
                throw ApiException::conflict('Reservation is cancelled.');
            }
            $r->payment()->update([
                'payment_status' => 'paid',
                'payment_method' => $method,
                'payment_date'   => Format::nowUtc(),
                'recorded_by'    => $adminId,
            ]);
        });
    }

    /** Checks a ticket code at the entrance and lets the attendee in if it's valid (from the panel's comment). */
    public function checkIn(mixed $code, mixed $eventId, int $adminId): array
    {
        $code = strtoupper(trim(is_string($code) ? $code : ''));
        if ($code === '') {
            return ['level' => 'danger', 'code' => 'empty', 'message' => 'Enter or scan a ticket code.'];
        }
        $eventId = ($eventId === null || $eventId === '') ? null : Validator::int($eventId, 'Event', 1, PHP_INT_MAX);

        return DB::transaction(function () use ($code, $eventId, $adminId) {
            // We lock the row so if the same code is scanned twice at the same time, the second scan says "already checked in".
            $a = AttendeeDetail::where('ticket_code', $code)->lockForUpdate()->first();
            if (!$a) {
                return ['level' => 'danger', 'code' => 'invalid', 'message' => 'Invalid ticket — code not found or ticket was voided.'];
            }
            $r = $a->reservation;
            $event = $r->ticketType->event;
            $base = ['attendee_ID' => (int) $a->attendee_ID, 'reservation_ID' => (int) $a->reservation_ID];
            if ($eventId !== null && (int) $event->event_ID !== $eventId) {
                return $base + ['level' => 'danger', 'code' => 'wrong_event', 'message' => "Wrong event — this ticket is for “{$event->event_name}”."];
            }
            if ($r->reservation_status !== 'confirmed') {
                return $base + ['level' => 'danger', 'code' => 'not_confirmed', 'message' => "Reservation is {$r->reservation_status}. Only confirmed tickets are valid."];
            }
            if ((int) $a->status === 1) {
                return $base + ['level' => 'warning', 'code' => 'already_used', 'message' => 'Already checked in.', 'checked_in_at' => Format::iso($a->checked_in_at)];
            }
            $now = Format::nowUtc();
            $a->update(['status' => 1, 'checked_in_at' => $now, 'checked_in_by' => $adminId]);
            return $base + ['level' => 'success', 'code' => 'ok', 'message' => 'Valid ticket — attendee checked in.', 'checked_in_at' => Format::iso($now)];
        });
    }

    /** Undo button for a wrong check-in. Only works if the attendee is checked in and the reservation is confirmed. */
    public function undoCheckIn(int $attendeeId): void
    {
        DB::transaction(function () use ($attendeeId) {
            $rid = AttendeeDetail::where('attendee_ID', $attendeeId)->value('reservation_ID') ?? throw ApiException::notFound('Attendee not found.');
            $r = $this->lockReservation((int) $rid);  // same locking order as cancel and confirm
            $a = AttendeeDetail::lockForUpdate()->find($attendeeId);
            if ((int) $a->status !== 1) {
                throw ApiException::conflict('This attendee is not checked in.', 'not_checked_in');
            }
            if ($r->reservation_status !== 'confirmed') {
                throw ApiException::conflict('Only check-ins on a confirmed reservation can be undone.', 'not_confirmed');
            }
            $a->update(['status' => 0, 'checked_in_at' => null, 'checked_in_by' => null]);
        });
    }

    private function lockReservation(int $id): Reservation
    {
        return Reservation::lockForUpdate()->find($id) ?? throw ApiException::notFound('Reservation not found.');
    }
}
