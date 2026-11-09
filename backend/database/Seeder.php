<?php
declare(strict_types=1);

use ETicket\Core\Database;
use ETicket\Services\EventService;
use ETicket\Services\ReservationService;
use ETicket\Services\UserService;

/** Demo data matching the original front-end prototype, created through the real services. */
final class Seeder
{
    public function run(): void
    {
        $users = new UserService();
        $events = new EventService();
        $res = new ReservationService();

        $admin = $users->create('Event Administrator', 'admin@eticket.test', 'admin123', 'admin');
        $maria = $users->create('Maria Santos', 'attendee@eticket.test', 'attendee123', 'attendee');
        $juan = $users->create('Juan Dela Cruz', 'juan@eticket.test', 'attendee123', 'attendee');
        $ana = $users->create('Ana Reyes', 'ana@eticket.test', 'attendee123', 'attendee');
        $adminId = $admin['user_ID'];

        $day = static fn (int $n): string => date('Y-m-d', strtotime("+$n days"));
        $make = static fn (string $name, string $cat, int $off, string $time, string $venue, string $desc, array $types) => $events->save(null, [
            'event_name' => $name, 'category' => $cat, 'event_date' => $day($off), 'event_time' => $time, 'venue' => $venue, 'description' => $desc,
            'ticket_types' => array_map(static fn ($t) => ['type_name' => $t[0], 'price' => $t[1], 'total' => $t[2]], $types),
        ], $adminId);

        $fun = $make('Fun Run 2026', 'Sports', 21, '05:30', 'TSU Main Campus Oval, Tarlac City', '5K community fun run open to students and the public. Includes race bib, finisher shirt and hydration stations.', [['Regular', 0, 50]]);
        $summit = $make('CCS Tech Summit', 'Seminar', 14, '09:00', 'TSU Convention Hall, Tarlac City', 'A one-day conference on web, mobile and cloud development with industry speakers and a career fair.', [['Regular', 250, 300], ['VIP', 750, 40]]);
        $make('Campus Acoustic Night', 'Concert', 30, '18:00', 'TSU Amphitheater, Tarlac City', 'An evening of live acoustic performances by student bands and guest artists.', [['General Admission', 150, 200], ['VIP', 400, 30]]);
        $make('Web Dev Workshop: Laravel Basics', 'Workshop', 7, '13:00', 'CCS Computer Lab 2, TSU', 'Hands-on workshop covering routing, controllers and Eloquent. Bring your own laptop.', [['Regular', 100, 30]]);

        $firstTicket = static function (int $eventId): int {
            $st = Database::pdo()->prepare('SELECT ticket_ID FROM ticket_types WHERE event_ID = ? ORDER BY ticket_ID LIMIT 1');
            $st->execute([$eventId]);
            return (int) $st->fetchColumn();
        };
        $book = function (array $user, int $eventId, array $names, bool $confirm, bool $paid, string $method = 'Pay at venue (cash)') use ($res, $firstTicket, $adminId): int {
            $rid = $res->reserve($user['user_ID'], [
                'ticket_ID'      => $firstTicket($eventId),
                'quantity'       => count($names),
                'payment_method' => $method,
                'attendees'      => array_map(static fn ($n) => ['full_name' => $n, 'contact_number' => '0917 000 ' . random_int(1000, 9999)], $names),
            ]);
            if ($confirm) {
                $res->confirm($rid);
            }
            if ($paid) {
                $res->markPaid($rid, 'Cash', $adminId);
            }
            return $rid;
        };

        $r1 = $book($maria, $summit, ['Maria Santos', 'Carlo Santos'], true, true);
        $book($juan, $fun, ['Juan Dela Cruz', 'Liza Dela Cruz', 'Marco Dela Cruz'], true, true);
        $book($ana, $summit, ['Ana Reyes'], false, false);
        $book($maria, $fun, ['Maria Santos'], false, false);
        $r5 = $book($juan, $summit, ['Juan Dela Cruz'], false, false);
        $res->cancel($r5, ['role' => 'admin', 'user_ID' => $adminId]);

        $st = Database::pdo()->prepare('SELECT ticket_code FROM attendee_details WHERE reservation_ID = ? ORDER BY attendee_ID LIMIT 1');
        $st->execute([$r1]);
        $res->checkIn((string) $st->fetchColumn(), null, $adminId);
    }
}
