<?php
declare(strict_types=1);

namespace ETicket\Services;

use DateTimeImmutable;
use DateTimeZone;
use ETicket\Core\Config;
use ETicket\Core\ConflictException;
use ETicket\Core\Database;
use ETicket\Core\ForbiddenException;
use ETicket\Core\NotFoundException;
use ETicket\Core\SoldOutException;
use ETicket\Core\ValidationException;
use ETicket\Support\Format;
use ETicket\Support\Validator;
use PDO;

/**
 * Reservations, inventory and e-ticket issuing.
 *
 * Inventory rule (panel comment #5):
 *   PENDING and CONFIRMED reservations both HOLD seats – quantity_available is reduced when the
 *   reservation is submitted. CANCELLED releases them. Every change to ticket_types happens in a
 *   transaction that first locks the ticket_types row (SELECT … FOR UPDATE), so two attendees can
 *   never oversell the last ticket.
 */
final class ReservationService
{
    public const MAX_PER_RESERVATION = 10;
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no I, O, 0, 1

    /**
     * @param array<string,mixed> $in
     * @return int reservation_ID
     */
    public function reserve(int $userId, array $in): int
    {
        $ticketId = Validator::int($in['ticket_ID'] ?? null, 'Ticket type', 1, PHP_INT_MAX);
        $qty = Validator::int($in['quantity'] ?? null, 'Quantity', 1, self::MAX_PER_RESERVATION);
        $method = Validator::string($in['payment_method'] ?? null, 'Payment method', 1, 100);
        $attendees = $this->validateAttendees($in['attendees'] ?? null, $qty);

        return Database::transaction(function (PDO $db) use ($userId, $ticketId, $qty, $method, $attendees) {
            // Lock the ticket-type row: concurrent reservations for it queue up here.
            $st = $db->prepare('SELECT * FROM ticket_types WHERE ticket_ID = ? FOR UPDATE');
            $st->execute([$ticketId]);
            $ticket = $st->fetch();
            if (!$ticket) {
                throw new NotFoundException('Ticket type not found.');
            }
            $ev = $db->prepare('SELECT event_name, event_date, event_time FROM events WHERE event_ID = ?');
            $ev->execute([$ticket['event_ID']]);
            $event = $ev->fetch();

            $starts = new DateTimeImmutable($event['event_date'] . ' ' . $event['event_time'], new DateTimeZone(date_default_timezone_get()));
            if ($starts <= new DateTimeImmutable('now')) {
                throw new ConflictException('This event has already started, so it can no longer be reserved.', 'event_closed');
            }

            $left = (int) $ticket['quantity_available'];
            if ($qty > $left) {
                throw new SoldOutException($left > 0
                    ? "Only $left ticket(s) left for {$ticket['type_name']}."
                    : "{$ticket['type_name']} is sold out.");
            }

            $db->prepare('UPDATE ticket_types SET quantity_available = quantity_available - ?, quantity_sold = quantity_sold + ? WHERE ticket_ID = ?')
                ->execute([$qty, $qty, $ticketId]);

            $total = round((float) $ticket['price'] * $qty, 2);
            $now = Format::nowUtc();
            $db->prepare("INSERT INTO reservations (user_ID, ticket_ID, quantity, total_amount, reservation_status, reservation_date) VALUES (?,?,?,?, 'pending', ?)")
                ->execute([$userId, $ticketId, $qty, $total, $now]);
            $rid = (int) $db->lastInsertId();

            // Exactly one attendee row per ticket (panel comment #6).
            $ins = $db->prepare('INSERT INTO attendee_details (reservation_ID, full_name, contact_number) VALUES (?,?,?)');
            foreach ($attendees as $a) {
                $ins->execute([$rid, $a['full_name'], $a['contact_number']]);
            }

            // Payment is a record only – no gateway (panel comment #4). Free events are paid by definition.
            $free = $total == 0.0;
            $db->prepare('INSERT INTO payments (reservation_ID, amount, payment_method, payment_status, payment_date) VALUES (?,?,?,?,?)')
                ->execute([$rid, $total, $free ? 'Free' : $method, $free ? 'paid' : 'unpaid', $free ? $now : null]);
            return $rid;
        });
    }

    /**
     * @return list<array{full_name:string,contact_number:?string}>
     */
    private function validateAttendees(mixed $raw, int $qty): array
    {
        if (!is_array($raw) || count($raw) !== $qty) {
            throw new ValidationException('Enter the name of every attendee (one per ticket).');
        }
        $out = [];
        foreach (array_values($raw) as $a) {
            if (!is_array($a)) {
                throw new ValidationException('Enter the name of every attendee (one per ticket).');
            }
            $name = Validator::string($a['full_name'] ?? null, 'Attendee name', 1, 100);
            $contact = Validator::optionalString($a['contact_number'] ?? null, 'Contact number', 20);
            if ($contact !== '' && !preg_match('/^[0-9+()\-\s]{3,20}$/', $contact)) {
                throw new ValidationException('Contact number may only contain digits, spaces, + ( ) and -.');
            }
            $out[] = ['full_name' => $name, 'contact_number' => $contact === '' ? null : $contact];
        }
        return $out;
    }

    /** Confirms a pending reservation and issues one unique e-ticket code per attendee. */
    public function confirm(int $reservationId): void
    {
        Database::transaction(function (PDO $db) use ($reservationId) {
            $r = $this->lockReservation($db, $reservationId);
            if ($r['reservation_status'] === 'cancelled') {
                throw new ConflictException('A cancelled reservation cannot be confirmed.');
            }
            if ($r['reservation_status'] === 'confirmed') {
                return;
            }
            $db->prepare("UPDATE reservations SET reservation_status = 'confirmed' WHERE reservation_ID = ?")->execute([$reservationId]);

            $st = $db->prepare('SELECT attendee_ID, ticket_code FROM attendee_details WHERE reservation_ID = ? ORDER BY attendee_ID FOR UPDATE');
            $st->execute([$reservationId]);
            $set = $db->prepare('UPDATE attendee_details SET ticket_code = ? WHERE attendee_ID = ?');
            foreach ($st->fetchAll() as $i => $a) {
                if ($a['ticket_code'] === null) {
                    $set->execute([$this->newTicketCode($db, $reservationId, $i + 1), $a['attendee_ID']]);
                }
            }
        });
    }

    /** TKT-<reservation>-<n>-<8 random chars>; retried in the (very unlikely) event of a collision. */
    private function newTicketCode(PDO $db, int $reservationId, int $n): string
    {
        $chk = $db->prepare('SELECT 1 FROM attendee_details WHERE ticket_code = ?');
        do {
            $rand = '';
            for ($i = 0; $i < 8; $i++) {
                $rand .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
            $code = sprintf('TKT-%03d-%d-%s', $reservationId, $n, $rand);
            $chk->execute([$code]);
        } while ($chk->fetchColumn());
        return $code;
    }

    /**
     * Cancels a reservation, releases its seats and voids its tickets.
     * Attendees may cancel only their own PENDING reservations; Administrators may cancel any
     * reservation that has no checked-in attendee.
     *
     * @param array<string,mixed> $actor
     */
    public function cancel(int $reservationId, array $actor): void
    {
        Database::transaction(function (PDO $db) use ($reservationId, $actor) {
            $r = $this->lockReservation($db, $reservationId);
            $isAdmin = $actor['role'] === 'admin';
            if (!$isAdmin) {
                if ((int) $r['user_ID'] !== (int) $actor['user_ID']) {
                    throw new NotFoundException('Reservation not found.'); // do not reveal other people's ids
                }
                if ($r['reservation_status'] === 'confirmed') {
                    throw new ForbiddenException('A confirmed reservation can only be cancelled by an Administrator.');
                }
            }
            if ($r['reservation_status'] === 'cancelled') {
                return;
            }
            $in = $db->prepare('SELECT COUNT(*) FROM attendee_details WHERE reservation_ID = ? AND status = 1');
            $in->execute([$reservationId]);
            if ((int) $in->fetchColumn() > 0) {
                throw new ConflictException('Cannot cancel: an attendee has already checked in.');
            }
            $this->release($db, $r);
        });
    }

    /** Cancels a LOCKED reservation row: releases its seats and voids its tickets. */
    private function release(PDO $db, array $r): void
    {
        // Lock order: reservation, then ticket type. reserve() only ever locks the ticket type, so no cycle.
        $db->prepare('SELECT ticket_ID FROM ticket_types WHERE ticket_ID = ? FOR UPDATE')->execute([$r['ticket_ID']]);
        $db->prepare('UPDATE ticket_types SET quantity_available = quantity_available + ?, quantity_sold = quantity_sold - ? WHERE ticket_ID = ?')
            ->execute([$r['quantity'], $r['quantity'], $r['ticket_ID']]);
        $db->prepare("UPDATE reservations SET reservation_status = 'cancelled' WHERE reservation_ID = ?")->execute([$r['reservation_ID']]);
        $db->prepare('UPDATE attendee_details SET ticket_code = NULL WHERE reservation_ID = ?')->execute([$r['reservation_ID']]); // ticket voided
    }

    /** Hours an unpaid Pending reservation may hold seats; 0 turns automatic expiry off. */
    public static function holdHours(): int
    {
        return max(0, (int) Config::get('RESERVATION_HOLD_HOURS', '48'));
    }

    /**
     * Automatic expiry: cancels Pending reservations that are still UNPAID after the hold window
     * and gives their seats back. Paid (and free) Pending reservations are left for the
     * Administrator to confirm. Runs at the start of every API request; the query is indexed and
     * each expiry is its own short transaction, re-checked under lock.
     *
     * @return int number of reservations expired
     */
    public function expireStale(): int
    {
        $hours = self::holdHours();
        if ($hours === 0) {
            return 0;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - $hours * 3600);
        $st = Database::pdo()->prepare("SELECT r.reservation_ID FROM reservations r JOIN payments p ON p.reservation_ID = r.reservation_ID
            WHERE r.reservation_status = 'pending' AND p.payment_status = 'unpaid' AND r.reservation_date < ? LIMIT 50");
        $st->execute([$cutoff]);
        $expired = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) {
            $expired += Database::transaction(function (PDO $db) use ($rid, $cutoff) {
                $r = $this->lockReservation($db, (int) $rid);
                $p = $db->prepare('SELECT payment_status FROM payments WHERE reservation_ID = ? FOR UPDATE');
                $p->execute([$rid]);
                // Re-check under lock: an Administrator may have confirmed or recorded payment meanwhile.
                if ($r['reservation_status'] !== 'pending' || $p->fetchColumn() !== 'unpaid' || $r['reservation_date'] >= $cutoff) {
                    return 0;
                }
                $this->release($db, $r);
                return 1;
            });
        }
        return $expired;
    }

    /** Administrator records that payment was received (cash / bank / e-wallet). */
    public function markPaid(int $reservationId, mixed $method, int $adminId): void
    {
        $method = Validator::string($method ?? 'Cash', 'Payment method', 1, 100);
        Database::transaction(function (PDO $db) use ($reservationId, $method, $adminId) {
            $r = $this->lockReservation($db, $reservationId);
            if ($r['reservation_status'] === 'cancelled') {
                throw new ConflictException('Reservation is cancelled.');
            }
            $db->prepare("UPDATE payments SET payment_status = 'paid', payment_method = ?, payment_date = ?, recorded_by = ? WHERE reservation_ID = ?")
                ->execute([$method, Format::nowUtc(), $adminId, $reservationId]);
        });
    }

    /**
     * Validates a ticket code at the entrance and admits the attendee (panel comment #7).
     *
     * @return array<string,mixed> {level, code, message, attendee_ID?, reservation_ID?, checked_in_at?}
     */
    public function checkIn(mixed $code, mixed $eventId, int $adminId): array
    {
        $code = strtoupper(trim(is_string($code) ? $code : ''));
        if ($code === '') {
            return ['level' => 'danger', 'code' => 'empty', 'message' => 'Enter or scan a ticket code.'];
        }
        $eventId = ($eventId === null || $eventId === '') ? null : Validator::int($eventId, 'Event', 1, PHP_INT_MAX);

        return Database::transaction(function (PDO $db) use ($code, $eventId, $adminId) {
            // FOR UPDATE: if the same code is scanned twice at once, one request waits and sees "already checked in".
            $st = $db->prepare('SELECT a.attendee_ID, a.reservation_ID, a.status, a.checked_in_at, r.reservation_status, t.event_ID, e.event_name
                FROM attendee_details a
                JOIN reservations r ON r.reservation_ID = a.reservation_ID
                JOIN ticket_types t ON t.ticket_ID = r.ticket_ID
                JOIN events e ON e.event_ID = t.event_ID
                WHERE a.ticket_code = ? FOR UPDATE');
            $st->execute([$code]);
            $a = $st->fetch();
            if (!$a) {
                return ['level' => 'danger', 'code' => 'invalid', 'message' => 'Invalid ticket — code not found or ticket was voided.'];
            }
            $base = ['attendee_ID' => (int) $a['attendee_ID'], 'reservation_ID' => (int) $a['reservation_ID']];
            if ($eventId !== null && (int) $a['event_ID'] !== $eventId) {
                return $base + ['level' => 'danger', 'code' => 'wrong_event', 'message' => "Wrong event — this ticket is for “{$a['event_name']}”."];
            }
            if ($a['reservation_status'] !== 'confirmed') {
                return $base + ['level' => 'danger', 'code' => 'not_confirmed', 'message' => "Reservation is {$a['reservation_status']}. Only confirmed tickets are valid."];
            }
            if ((int) $a['status'] === 1) {
                return $base + ['level' => 'warning', 'code' => 'already_used', 'message' => 'Already checked in.', 'checked_in_at' => Format::iso($a['checked_in_at'])];
            }
            $now = Format::nowUtc();
            $db->prepare('UPDATE attendee_details SET status = 1, checked_in_at = ?, checked_in_by = ? WHERE attendee_ID = ?')
                ->execute([$now, $adminId, $a['attendee_ID']]);
            return $base + ['level' => 'success', 'code' => 'ok', 'message' => 'Valid ticket — attendee checked in.', 'checked_in_at' => Format::iso($now)];
        });
    }

    /** Reverses a mistaken check-in. Only a checked-in attendee of a confirmed reservation can be undone. */
    public function undoCheckIn(int $attendeeId): void
    {
        Database::transaction(function (PDO $db) use ($attendeeId) {
            // Lock the reservation first (same order as cancel/confirm), then the attendee row.
            $st = $db->prepare('SELECT reservation_ID FROM attendee_details WHERE attendee_ID = ?');
            $st->execute([$attendeeId]);
            $rid = $st->fetchColumn();
            if ($rid === false) {
                throw new NotFoundException('Attendee not found.');
            }
            $r = $this->lockReservation($db, (int) $rid);
            $a = $db->prepare('SELECT status FROM attendee_details WHERE attendee_ID = ? FOR UPDATE');
            $a->execute([$attendeeId]);
            if ((int) $a->fetchColumn() !== 1) {
                throw new ConflictException('This attendee is not checked in.', 'not_checked_in');
            }
            if ($r['reservation_status'] !== 'confirmed') {
                throw new ConflictException('Only check-ins on a confirmed reservation can be undone.', 'not_confirmed');
            }
            $db->prepare('UPDATE attendee_details SET status = 0, checked_in_at = NULL, checked_in_by = NULL WHERE attendee_ID = ?')
                ->execute([$attendeeId]);
        });
    }

    /** @return array<string,mixed> the locked reservation row */
    private function lockReservation(PDO $db, int $id): array
    {
        $st = $db->prepare('SELECT * FROM reservations WHERE reservation_ID = ? FOR UPDATE');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) {
            throw new NotFoundException('Reservation not found.');
        }
        return $r;
    }
}
