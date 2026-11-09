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
 * Reservations, inventory and e-ticket issuing.
 *
 * Inventory rule (panel comment #5): PENDING and CONFIRMED reservations HOLD seats
 * (quantity_available is reduced when the reservation is submitted); CANCELLED releases them.
 * Every change to ticket_types runs inside DB::transaction() after lockForUpdate() on the
 * ticket-type row (SELECT … FOR UPDATE), so two attendees can never oversell the last ticket.
 */
class ReservationService
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no I, O, 0, 1

    /** @return int reservation_ID */
    public function reserve(int $userId, array $in): int
    {
        $ticketId = Validator::int($in['ticket_ID'] ?? null, 'Ticket type', 1, PHP_INT_MAX);
        $qty = Validator::int($in['quantity'] ?? null, 'Quantity', 1, config('eticket.max_per_reservation'));
        $method = Validator::string($in['payment_method'] ?? null, 'Payment method', 1, 100);
        $attendees = $this->validateAttendees($in['attendees'] ?? null, $qty);

        return DB::transaction(function () use ($userId, $ticketId, $qty, $method, $attendees) {
            // Lock the ticket-type row: concurrent reservations for it queue up here.
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
            // Exactly one attendee row per ticket (panel comment #6).
            $reservation->attendees()->createMany($attendees);

            // Payment is a record only – no gateway (panel comment #4). Free events are paid by definition.
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

    /** Confirms a pending reservation and issues one unique e-ticket code per attendee. */
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

    /** TKT-<reservation>-<n>-<8 random chars>; retried in the (very unlikely) event of a collision. */
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
     * Cancels a reservation, releases its seats and voids its tickets. Attendees may cancel only their
     * own PENDING reservations; Administrators any reservation with no checked-in attendee.
     */
    public function cancel(int $reservationId, User $actor): void
    {
        DB::transaction(function () use ($reservationId, $actor) {
            $r = $this->lockReservation($reservationId);
            if (!$actor->isAdmin()) {
                if ((int) $r->user_ID !== (int) $actor->user_ID) {
                    throw ApiException::notFound('Reservation not found.'); // do not reveal other people's ids
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

    /** Cancels a LOCKED reservation: releases its seats and voids its tickets. */
    private function release(Reservation $r): void
    {
        // Lock order: reservation, then ticket type. reserve() only ever locks the ticket type, so no cycle.
        $qty = (int) $r->quantity;
        TicketType::lockForUpdate()->find($r->ticket_ID);
        TicketType::whereKey($r->ticket_ID)->update([
            'quantity_available' => DB::raw('quantity_available + ' . $qty),
            'quantity_sold'      => DB::raw('quantity_sold - ' . $qty),
        ]);
        $r->update(['reservation_status' => 'cancelled']);
        AttendeeDetail::where('reservation_ID', $r->reservation_ID)->update(['ticket_code' => null]); // ticket voided
    }

    public static function holdHours(): int
    {
        return max(0, config('eticket.hold_hours'));
    }

    /**
     * Automatic expiry: cancels Pending reservations still UNPAID after the hold window and gives
     * their seats back. Each one is its own short transaction, re-checked under lock.
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
                    return 0; // an Administrator confirmed or recorded payment meanwhile
                }
                $this->release($r);
                return 1;
            });
        }
        return $expired;
    }

    /** Administrator records that payment was received (cash / bank / e-wallet). */
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

    /** Validates a ticket code at the entrance and admits the attendee (panel comment #7). */
    public function checkIn(mixed $code, mixed $eventId, int $adminId): array
    {
        $code = strtoupper(trim(is_string($code) ? $code : ''));
        if ($code === '') {
            return ['level' => 'danger', 'code' => 'empty', 'message' => 'Enter or scan a ticket code.'];
        }
        $eventId = ($eventId === null || $eventId === '') ? null : Validator::int($eventId, 'Event', 1, PHP_INT_MAX);

        return DB::transaction(function () use ($code, $eventId, $adminId) {
            // lockForUpdate: if the same code is scanned twice at once, one request waits and sees "already checked in".
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

    /** Reverses a mistaken check-in. Only a checked-in attendee of a confirmed reservation can be undone. */
    public function undoCheckIn(int $attendeeId): void
    {
        DB::transaction(function () use ($attendeeId) {
            $rid = AttendeeDetail::where('attendee_ID', $attendeeId)->value('reservation_ID') ?? throw ApiException::notFound('Attendee not found.');
            $r = $this->lockReservation((int) $rid);  // same lock order as cancel/confirm
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
