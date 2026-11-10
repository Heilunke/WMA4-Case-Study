<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\TicketType;
use App\Support\Format;
use App\Support\Validator;
use Illuminate\Support\Facades\DB;

/** Event catalogue (any user) and event / ticket-type management (Administrator). */
class EventService
{
    /**
     * GET /api/events — events ordered by date and time, each with its ticket types and seat counts.
     * Optional filters: q (name or venue contains), category (exact), date (exact day),
     * from (on or after this day).
     */
    public function catalog(array $filters): array
    {
        $query = Event::with(['ticketTypes' => fn ($t) => $t->orderBy('ticket_ID')])
            ->orderBy('event_date')->orderBy('event_time')->orderBy('event_ID');

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query->where(fn ($w) => $w->where('event_name', 'like', '%' . $q . '%')->orWhere('venue', 'like', '%' . $q . '%'));
        }
        if (($filters['category'] ?? '') !== '') {
            $query->where('category', Validator::string($filters['category'], 'Category', 1, 50));
        }
        if (($filters['date'] ?? '') !== '') {
            $query->where('event_date', Validator::date($filters['date'], 'Date'));
        }
        if (($filters['from'] ?? '') !== '') {
            $query->where('event_date', '>=', Validator::date($filters['from'], 'From date'));
        }
        return $query->get()->map(fn (Event $e) => $this->withTickets($e))->all();
    }

    /** GET /api/events/{id} — one event with its ticket types and seat counts. */
    public function find(int $eventId): array
    {
        $event = Event::with(['ticketTypes' => fn ($t) => $t->orderBy('ticket_ID')])->find($eventId)
            ?? throw ApiException::notFound('Event not found.');
        return $this->withTickets($event);
    }

    /** Event JSON + ticket_types + seats {left, total, sold} (seats held by Pending and Confirmed reservations). */
    private function withTickets(Event $event): array
    {
        $types = $event->ticketTypes;
        $left = (int) $types->sum('quantity_available');
        $sold = (int) $types->sum('quantity_sold');
        return Format::event($event) + [
            'ticket_types' => $types->map(fn (TicketType $t) => Format::ticketType($t))->all(),
            'seats'        => ['left' => $left, 'total' => $left + $sold, 'sold' => $sold],
        ];
    }

    /**
     * Creates or updates an event and reconciles its ticket types in one transaction.
     * quantity_available is always recomputed as capacity − seats already held.
     */
    public function save(?int $eventId, array $in, int $adminId): int
    {
        $data = [
            'event_name'  => Validator::string($in['event_name'] ?? null, 'Event name', 1, 150),
            'category'    => Validator::optionalString($in['category'] ?? null, 'Category', 50) ?: 'General',
            'description' => Validator::optionalString($in['description'] ?? null, 'Description', 5000),
            'event_date'  => Validator::date($in['event_date'] ?? null, 'Date'),
            'event_time'  => Validator::time($in['event_time'] ?? null, 'Start time'),
            'venue'       => Validator::string($in['venue'] ?? null, 'Venue', 1, 150),
        ];
        $types = $this->validateTypes($in['ticket_types'] ?? null);

        return DB::transaction(function () use ($eventId, $data, $types, $adminId) {
            if ($eventId === null) {
                $event = Event::create($data + ['user_ID' => $adminId, 'created_at' => Format::nowUtc()]);
            } else {
                $event = Event::lockForUpdate()->find($eventId) ?? throw ApiException::notFound('Event not found.');
                $event->update($data);
            }

            $existing = $event->ticketTypes()->lockForUpdate()->get()->keyBy('ticket_ID');
            $keep = [];
            foreach ($types as $t) {
                if ($t['ticket_ID'] !== null) {
                    $row = $existing->get($t['ticket_ID']) ?? throw ApiException::validation('A ticket type does not belong to this event.');
                    $sold = (int) $row->quantity_sold;
                    if ($t['total'] < $sold) {
                        throw ApiException::conflict("{$row->type_name}: capacity cannot be lower than the $sold ticket(s) already reserved.");
                    }
                    $row->update(['type_name' => $t['type_name'], 'price' => $t['price'], 'quantity_available' => $t['total'] - $sold]);
                    $keep[$t['ticket_ID']] = true;
                } else {
                    $new = $event->ticketTypes()->create([
                        'type_name' => $t['type_name'], 'price' => $t['price'], 'quantity_available' => $t['total'], 'quantity_sold' => 0,
                    ]);
                    $keep[$new->ticket_ID] = true;
                }
            }
            foreach ($existing as $id => $row) {
                if (isset($keep[$id])) {
                    continue;
                }
                if ($row->reservations()->exists()) {
                    throw ApiException::conflict("Cannot remove {$row->type_name}: it already has reservations.");
                }
                $row->delete();
            }
            return (int) $event->event_ID;
        });
    }

    private function validateTypes(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            throw ApiException::validation('Add at least one ticket type.');
        }
        if (count($raw) > 10) {
            throw ApiException::validation('An event can have at most 10 ticket types.');
        }
        $out = [];
        $seen = [];
        foreach ($raw as $t) {
            if (!is_array($t)) {
                throw ApiException::validation('Invalid ticket type.');
            }
            $name = Validator::string($t['type_name'] ?? null, 'Ticket type name', 1, 50);
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                throw ApiException::validation("Ticket type \"$name\" is listed twice.");
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
        DB::transaction(function () use ($eventId) {
            $event = Event::lockForUpdate()->find($eventId) ?? throw ApiException::notFound('Event not found.');
            if ($event->reservations()->where('reservation_status', '<>', 'cancelled')->exists()) {
                throw ApiException::conflict('This event still has active reservations. Cancel them first.');
            }
            // Only cancelled reservations remain; their attendees/payments cascade-delete with them.
            Reservation::whereIn('ticket_ID', TicketType::where('event_ID', $eventId)->select('ticket_ID'))->delete();
            $event->delete(); // ticket_types cascade
        });
    }
}
