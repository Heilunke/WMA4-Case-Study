<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A reservation (RSV-###): one ticket type × quantity, one attendee row per ticket, one payment record. */
class Reservation extends Model
{
    protected $table = 'reservations';
    protected $primaryKey = 'reservation_ID';
    public $timestamps = false;
    protected $guarded = ['reservation_ID'];

    /** The attendee account that booked it. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_ID', 'user_ID');
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class, 'ticket_ID', 'ticket_ID');
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(AttendeeDetail::class, 'reservation_ID', 'reservation_ID')->orderBy('attendee_ID');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class, 'reservation_ID', 'reservation_ID');
    }
}
