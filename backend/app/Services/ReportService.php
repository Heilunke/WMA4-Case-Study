<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Format;
use Illuminate\Support\Facades\DB;

/** Dashboard / report numbers, aggregated in SQL. */
class ReportService
{
    public function build(): array
    {
        $byStatus = ['confirmed' => 0, 'pending' => 0, 'cancelled' => 0];
        foreach (Reservation::select('reservation_status', DB::raw('COUNT(*) AS c'))->groupBy('reservation_status')->get() as $row) {
            $byStatus[$row->reservation_status] = (int) $row->c;
        }

        // One row per event. Cancelled reservations never count toward reserved/confirmed/revenue/check-in.
        $rows = DB::select("
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
            FROM events e ORDER BY e.event_date, e.event_time, e.event_ID");
        $perEvent = array_map(fn ($r) => [
            'event'     => Format::event($r),
            'capacity'  => (int) $r->capacity,
            'reserved'  => (int) $r->reserved,
            'confirmed' => (int) $r->confirmed,
            'revenue'   => (float) $r->revenue,
            'checkedIn' => (int) $r->checked_in,
        ], $rows);

        return [
            'byStatus' => $byStatus,
            'perEvent' => $perEvent,
            'totals'   => [
                'events'       => Event::count(),
                'reservations' => array_sum($byStatus),
                'users'        => User::count(),
                'pending'      => $byStatus['pending'],
            ],
        ];
    }
}
