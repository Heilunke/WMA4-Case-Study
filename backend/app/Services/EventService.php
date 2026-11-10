<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\TicketType;
use App\Support\Format;
use App\Support\Validator;
use Illuminate\Support\Facades\DB;

/** Handles the events: listing them for everyone, and adding/editing/deleting them for the admin. */
class EventService
{
    /**
     * GET /api/events - returns the events sorted by date and time, together with their ticket types
     * and how many seats are left. You can also search and filter:
     *   q = search the event name or venue, category = exact category,
     *   date = events on that day only, from = events on or after that day.
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

    /** GET /api/events/{id} - returns one event with its ticket types and seat counts. */
    public function find(int $eventId): array
    {
        $event = Event::with(['ticketTypes' => fn ($t) => $t->orderBy('ticket_ID')])->find($eventId)
            ?? throw ApiException::notFound('Event not found.');
        return $this->withTickets($event);
    }

    /** Builds the event data we send back, plus its ticket types and the seat totals (left, total, sold). */
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
     * Saves an event (new or edited) and its ticket types. Everything is done inside one transaction
     * so if something fails, nothing is saved halfway.
     * Note: quantity_available is always computed again as (capacity - seats already reserved).
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
            // At this point only cancelled reservations are left. Their attendees and payments are deleted with them (cascade).
            Reservation::whereIn('ticket_ID', TicketType::where('event_ID', $eventId)->select('ticket_ID'))->delete();
            $event->delete(); // the ticket types are also deleted because of the cascade
        });
    }
}
