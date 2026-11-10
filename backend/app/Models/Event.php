<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/** An event. Each event has one or more ticket types (like Regular and VIP). */
class Event extends Model
{
    protected $table = 'events';
    protected $primaryKey = 'event_ID';
    public $timestamps = false;
    protected $guarded = ['event_ID'];

    /** The admin who created the event. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_ID', 'user_ID');
    }

    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class, 'event_ID', 'event_ID');
    }

    /** All the reservations of this event (we reach them through the ticket types). */
    public function reservations(): HasManyThrough
    {
        return $this->hasManyThrough(Reservation::class, TicketType::class, 'event_ID', 'ticket_ID', 'event_ID', 'ticket_ID');
    }
}
