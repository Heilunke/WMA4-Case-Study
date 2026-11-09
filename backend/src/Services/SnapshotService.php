<?php
declare(strict_types=1);

namespace ETicket\Services;

use ETicket\Core\Database;
use ETicket\Core\NotFoundException;
use ETicket\Support\Format;
use PDO;

/**
 * Reads data in the shapes the front end uses, scoped by role:
 *   Administrator – everything.
 *   Attendee      – all events and ticket types (public catalogue) but ONLY their own
 *                   reservations, attendees and payments, and only their own user row.
 */
final class SnapshotService
{
    /**
     * @param array<string,mixed> $user the logged-in user
     * @return array<string,mixed>
     */
    public function forUser(array $user): array
    {
        $admin = $user['role'] === 'admin';
        $uid = (int) $user['user_ID'];
        $pdo = Database::pdo();

        $users = $admin
            ? $pdo->query('SELECT user_ID, full_Name, email, role, created_at FROM users ORDER BY user_ID')->fetchAll()
            : [$user];

        $events = $pdo->query('SELECT * FROM events ORDER BY event_date, event_time, event_ID')->fetchAll();
        $types = $pdo->query('SELECT * FROM ticket_types ORDER BY ticket_ID')->fetchAll();

        // $scope narrows reservations / attendees / payments to the caller for attendees.
        $scope = $admin ? '' : ' WHERE r.user_ID = ' . $uid;
        $reservations = $pdo->query("SELECT r.* FROM reservations r$scope ORDER BY r.reservation_ID")->fetchAll();
        $attendees = $pdo->query("SELECT a.* FROM attendee_details a JOIN reservations r ON r.reservation_ID = a.reservation_ID$scope ORDER BY a.attendee_ID")->fetchAll();
        $payments = $pdo->query("SELECT p.* FROM payments p JOIN reservations r ON r.reservation_ID = p.reservation_ID$scope ORDER BY p.payment_ID")->fetchAll();

        $data = [
            'users'            => array_map([Format::class, 'user'], $users),
            'events'           => array_map([Format::class, 'event'], $events),
            'ticket_types'     => array_map([Format::class, 'ticketType'], $types),
            'reservations'     => array_map([Format::class, 'reservation'], $reservations),
            'attendee_details' => array_map([Format::class, 'attendee'], $attendees),
            'payments'         => array_map([Format::class, 'payment'], $payments),
        ];
        if ($admin) {
            $data['report'] = (new ReportService())->build();
        }
        return $data;
    }

    /**
     * One reservation with its attendees and payment, or null if it does not exist / is not the caller's.
     *
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public function reservationFor(int $id, array $user): array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT * FROM reservations WHERE reservation_ID = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        // Same error for "missing" and "someone else's" so ids cannot be probed.
        if (!$r || ($user['role'] !== 'admin' && (int) $r['user_ID'] !== (int) $user['user_ID'])) {
            throw new NotFoundException('Reservation not found.');
        }
        $a = $pdo->prepare('SELECT * FROM attendee_details WHERE reservation_ID = ? ORDER BY attendee_ID');
        $a->execute([$id]);
        $p = $pdo->prepare('SELECT * FROM payments WHERE reservation_ID = ?');
        $p->execute([$id]);
        $t = $pdo->prepare('SELECT * FROM ticket_types WHERE ticket_ID = ?');
        $t->execute([$r['ticket_ID']]);
        $ticket = $t->fetch();
        $e = $pdo->prepare('SELECT * FROM events WHERE event_ID = ?');
        $e->execute([$ticket['event_ID']]);

        $payment = $p->fetch();
        return [
            'reservation' => Format::reservation($r),
            'attendees'   => array_map([Format::class, 'attendee'], $a->fetchAll(PDO::FETCH_ASSOC)),
            'payment'     => $payment ? Format::payment($payment) : null,
            'ticket_type' => Format::ticketType($ticket),
            'event'       => Format::event($e->fetch()),
        ];
    }
}
