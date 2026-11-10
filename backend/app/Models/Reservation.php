<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A reservation (shown as RSV-###). It has one ticket type, a quantity, one attendee per ticket and one payment. */
class Reservation extends Model
{
    protected $table = 'reservations';
    protected $primaryKey = 'reservation_ID';
    public $timestamps = false;
    protected $guarded = ['reservation_ID'];

    /** The attendee who made the reservation. */
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
