<?php
declare(strict_types=1);

namespace ETicket\Services;

use ETicket\Core\ConflictException;
use ETicket\Core\Database;
use ETicket\Core\NotFoundException;
use ETicket\Core\ValidationException;
use ETicket\Support\Format;
use ETicket\Support\Validator;
use PDO;

/** Event and ticket-type management (Administrator). */
final class EventService
{
    /**
     * Creates or updates an event and reconciles its ticket types in one transaction.
     * quantity_available is always recomputed as capacity − seats already held.
     *
     * @param array<string,mixed> $in
     * @return int the event_ID
     */
    public function save(?int $eventId, array $in, int $adminId): int
    {
        $name = Validator::string($in['event_name'] ?? null, 'Event name', 1, 150);
        $category = Validator::optionalString($in['category'] ?? null, 'Category', 50) ?: 'General';
        $desc = Validator::optionalString($in['description'] ?? null, 'Description', 5000);
        $date = Validator::date($in['event_date'] ?? null, 'Date');
        $time = Validator::time($in['event_time'] ?? null, 'Start time');
        $venue = Validator::string($in['venue'] ?? null, 'Venue', 1, 150);
        $types = $this->validateTypes($in['ticket_types'] ?? null);

        return Database::transaction(function (PDO $db) use ($eventId, $name, $category, $desc, $date, $time, $venue, $types, $adminId) {
            if ($eventId === null) {
                $db->prepare('INSERT INTO events (user_ID, event_name, category, description, event_date, event_time, venue, created_at) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$adminId, $name, $category, $desc, $date, $time, $venue, Format::nowUtc()]);
                $eventId = (int) $db->lastInsertId();
            } else {
                $lock = $db->prepare('SELECT event_ID FROM events WHERE event_ID = ? FOR UPDATE');
                $lock->execute([$eventId]);
                if (!$lock->fetch()) {
                    throw new NotFoundException('Event not found.');
                }
                $db->prepare('UPDATE events SET event_name=?, category=?, description=?, event_date=?, event_time=?, venue=? WHERE event_ID=?')
                    ->execute([$name, $category, $desc, $date, $time, $venue, $eventId]);
            }

            $st = $db->prepare('SELECT * FROM ticket_types WHERE event_ID = ? FOR UPDATE');
            $st->execute([$eventId]);
            /** @var array<int,array<string,mixed>> $existing */
            $existing = [];
            foreach ($st->fetchAll() as $row) {
                $existing[(int) $row['ticket_ID']] = $row;
            }

            $keep = [];
            foreach ($types as $t) {
                if ($t['ticket_ID'] !== null) {
                    $row = $existing[$t['ticket_ID']] ?? null;
                    if ($row === null) {
                        throw new ValidationException('A ticket type does not belong to this event.');
                    }
                    $sold = (int) $row['quantity_sold'];
                    if ($t['total'] < $sold) {
                        throw new ConflictException("{$row['type_name']}: capacity cannot be lower than the $sold ticket(s) already reserved.");
                    }
                    $db->prepare('UPDATE ticket_types SET type_name=?, price=?, quantity_available=? WHERE ticket_ID=?')
                        ->execute([$t['type_name'], $t['price'], $t['total'] - $sold, $t['ticket_ID']]);
                    $keep[$t['ticket_ID']] = true;
                } else {
                    $db->prepare('INSERT INTO ticket_types (event_ID, type_name, price, quantity_available, quantity_sold) VALUES (?,?,?,?,0)')
                        ->execute([$eventId, $t['type_name'], $t['price'], $t['total']]);
                    $keep[(int) $db->lastInsertId()] = true;
                }
            }
            foreach ($existing as $id => $row) {
                if (isset($keep[$id])) {
                    continue;
                }
                $used = $db->prepare('SELECT COUNT(*) FROM reservations WHERE ticket_ID = ?');
                $used->execute([$id]);
                if ((int) $used->fetchColumn() > 0) {
                    throw new ConflictException("Cannot remove {$row['type_name']}: it already has reservations.");
                }
                $db->prepare('DELETE FROM ticket_types WHERE ticket_ID = ?')->execute([$id]);
            }
            return $eventId;
        });
    }

    /**
     * @return list<array{ticket_ID:?int,type_name:string,price:float,total:int}>
     */
    private function validateTypes(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            throw new ValidationException('Add at least one ticket type.');
        }
        if (count($raw) > 10) {
            throw new ValidationException('An event can have at most 10 ticket types.');
        }
        $out = [];
        $seen = [];
        foreach ($raw as $t) {
            if (!is_array($t)) {
                throw new ValidationException('Invalid ticket type.');
            }
            $name = Validator::string($t['type_name'] ?? null, 'Ticket type name', 1, 50);
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                throw new ValidationException("Ticket type \"$name\" is listed twice.");
            }
            $seen[$key] = true;
            $id = $t['ticket_ID'] ?? null;
            $out[] = [
                'ticket_ID' => ($id === null || $id === '') ? null : Validator::int($id, 'Ticket type id', 1, PHP_INT_MAX),
                'type_name' => $name,
                'price'     => Validator::money($t['price'] ?? null, 'Price'),
                'total'     => Validator::int($t['total'] ?? null, 'Capacity', 1, 100000),
            ];
        }
        return $out;
    }

    public function delete(int $eventId): void
    {
        Database::transaction(function (PDO $db) use ($eventId) {
            $lock = $db->prepare('SELECT event_ID FROM events WHERE event_ID = ? FOR UPDATE');
            $lock->execute([$eventId]);
            if (!$lock->fetch()) {
                throw new NotFoundException('Event not found.');
            }
            $active = $db->prepare("SELECT COUNT(*) FROM reservations r JOIN ticket_types t ON t.ticket_ID = r.ticket_ID WHERE t.event_ID = ? AND r.reservation_status <> 'cancelled'");
            $active->execute([$eventId]);
            if ((int) $active->fetchColumn() > 0) {
                throw new ConflictException('This event still has active reservations. Cancel them first.');
            }
            // Only cancelled reservations remain; their attendees/payments cascade-delete with them.
            $db->prepare('DELETE r FROM reservations r JOIN ticket_types t ON t.ticket_ID = r.ticket_ID WHERE t.event_ID = ?')->execute([$eventId]);
            $db->prepare('DELETE FROM events WHERE event_ID = ?')->execute([$eventId]); // ticket_types cascade
        });
    }
}
