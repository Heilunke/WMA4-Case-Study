<?php
declare(strict_types=1);

namespace ETicket\Services;

use ETicket\Core\Database;
use ETicket\Support\Format;

/** Dashboard / report numbers, aggregated in SQL. */
final class ReportService
{
    /** @return array<string,mixed> */
    public function build(): array
    {
        $pdo = Database::pdo();

        $byStatus = ['confirmed' => 0, 'pending' => 0, 'cancelled' => 0];
        foreach ($pdo->query('SELECT reservation_status, COUNT(*) c FROM reservations GROUP BY reservation_status')->fetchAll() as $row) {
            $byStatus[$row['reservation_status']] = (int) $row['c'];
        }

        // One row per event. Cancelled reservations never count toward reserved/confirmed/revenue/check-in.
        $perEvent = [];
        $rows = $pdo->query("
            SELECT e.*,
                   COALESCE((SELECT SUM(t.quantity_available + t.quantity_sold) FROM ticket_types t WHERE t.event_ID = e.event_ID), 0) AS capacity,
                   COALESCE((SELECT SUM(t.quantity_sold) FROM ticket_types t WHERE t.event_ID = e.event_ID), 0) AS reserved,
                   COALESCE((SELECT SUM(r.quantity) FROM reservations r JOIN ticket_types t ON t.ticket_ID = r.ticket_ID
                             WHERE t.event_ID = e.event_ID AND r.reservation_status = 'confirmed'), 0) AS confirmed,
                   COALESCE((SELECT SUM(r.total_amount) FROM reservations r JOIN ticket_types t ON t.ticket_ID = r.ticket_ID
                             JOIN payments p ON p.reservation_ID = r.reservation_ID
                             WHERE t.event_ID = e.event_ID AND r.reservation_status <> 'cancelled' AND p.payment_status = 'paid'), 0) AS revenue,
                   COALESCE((SELECT COUNT(*) FROM attendee_details a JOIN reservations r ON r.reservation_ID = a.reservation_ID
                             JOIN ticket_types t ON t.ticket_ID = r.ticket_ID
                             WHERE t.event_ID = e.event_ID AND r.reservation_status <> 'cancelled' AND a.status = 1), 0) AS checked_in
            FROM events e ORDER BY e.event_date, e.event_time, e.event_ID")->fetchAll();
        foreach ($rows as $r) {
            $perEvent[] = [
                'event'     => Format::event($r),
                'capacity'  => (int) $r['capacity'],
                'reserved'  => (int) $r['reserved'],
                'confirmed' => (int) $r['confirmed'],
                'revenue'   => (float) $r['revenue'],
                'checkedIn' => (int) $r['checked_in'],
            ];
        }

        return [
            'byStatus' => $byStatus,
            'perEvent' => $perEvent,
            'totals'   => [
                'events'       => (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn(),
                'reservations' => array_sum($byStatus),
                'users'        => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
                'pending'      => $byStatus['pending'],
            ],
        ];
    }
}
