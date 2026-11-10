<?php

namespace App\Support;

use App\Models\AttendeeDetail;
use App\Models\Event;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Changes the database rows into the JSON format that the frontend uses (js/store.js uses these exact names).
 * We cast the numbers ourselves because MySQL gives DECIMAL values as text,
 * and we turn the UTC dates into ISO format so JavaScript can read them.
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

    /** @return array<string,mixed> raw column values of a model, or the row itself */
    private static function raw(Model|array|\stdClass $m): array
    {
        return $m instanceof Model ? $m->getAttributes() : (array) $m;
    }

    public static function user(User|array $u): array
    {
        $r = self::raw($u);
        return [
            'user_ID'    => (int) $r['user_ID'],
            'full_Name'  => $r['full_Name'],
            'email'      => $r['email'],
            'role'       => $r['role'],
            'created_at' => self::iso($r['created_at']),
        ];
    }

    public static function event(Event|array|\stdClass $e): array
    {
        $r = self::raw($e);
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

    public static function ticketType(TicketType $t): array
    {
        $r = self::raw($t);
        return [
            'ticket_ID'          => (int) $r['ticket_ID'],
            'event_ID'           => (int) $r['event_ID'],
            'type_name'          => $r['type_name'],
            'price'              => (float) $r['price'],
            'quantity_available' => (int) $r['quantity_available'],
            'quantity_sold'      => (int) $r['quantity_sold'],
        ];
    }

    public static function reservation(Reservation $res): array
    {
        $r = self::raw($res);
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

    public static function attendee(AttendeeDetail $a): array
    {
        $r = self::raw($a);
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

    public static function payment(Payment $p): array
    {
        $r = self::raw($p);
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
