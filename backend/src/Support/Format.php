<?php
declare(strict_types=1);

namespace ETicket\Support;

/**
 * Turns database rows into the exact JSON shapes the front end consumes.
 * Explicit casts matter: MySQL DECIMAL arrives as a string, TINYINT(1) as an int, and
 * timestamps are UTC strings that the browser needs as ISO-8601.
 */
final class Format
{
    public static function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function iso(?string $utc): ?string
    {
        return $utc === null ? null : str_replace(' ', 'T', $utc) . 'Z';
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function user(array $r): array
    {
        return [
            'user_ID'    => (int) $r['user_ID'],
            'full_Name'  => $r['full_Name'],
            'email'      => $r['email'],
            'role'       => $r['role'],
            'created_at' => self::iso($r['created_at']),
        ];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function event(array $r): array
    {
        return [
            'event_ID'    => (int) $r['event_ID'],
            'user_ID'     => (int) $r['user_ID'],
            'event_name'  => $r['event_name'],
            'category'    => $r['category'],
            'description' => $r['description'] ?? '',
            'event_date'  => $r['event_date'],
            'event_time'  => substr((string) $r['event_time'], 0, 5),
            'venue'       => $r['venue'],
        ];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function ticketType(array $r): array
    {
        return [
            'ticket_ID'          => (int) $r['ticket_ID'],
            'event_ID'           => (int) $r['event_ID'],
            'type_name'          => $r['type_name'],
            'price'              => (float) $r['price'],
            'quantity_available' => (int) $r['quantity_available'],
            'quantity_sold'      => (int) $r['quantity_sold'],
        ];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function reservation(array $r): array
    {
        return [
            'reservation_ID'     => (int) $r['reservation_ID'],
            'user_ID'            => (int) $r['user_ID'],
            'ticket_ID'          => (int) $r['ticket_ID'],
            'quantity'           => (int) $r['quantity'],
            'total_amount'       => (float) $r['total_amount'],
            'reservation_status' => $r['reservation_status'],
            'reservation_date'   => self::iso($r['reservation_date']),
        ];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function attendee(array $r): array
    {
        return [
            'attendee_ID'    => (int) $r['attendee_ID'],
            'reservation_ID' => (int) $r['reservation_ID'],
            'full_name'      => $r['full_name'],
            'contact_number' => $r['contact_number'],
            'ticket_code'    => $r['ticket_code'],
            'status'         => (bool) $r['status'],
            'checked_in_at'  => self::iso($r['checked_in_at']),
        ];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function payment(array $r): array
    {
        return [
            'payment_ID'     => (int) $r['payment_ID'],
            'reservation_ID' => (int) $r['reservation_ID'],
            'amount'         => (float) $r['amount'],
            'payment_method' => $r['payment_method'],
            'payment_status' => $r['payment_status'],
            'payment_date'   => self::iso($r['payment_date']),
        ];
    }
}
