<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A ticket type of an event (e.g. Regular, VIP) and its seat inventory. */
class TicketType extends Model
{
    protected $table = 'ticket_types';
    protected $primaryKey = 'ticket_ID';
    public $timestamps = false;
    protected $guarded = ['ticket_ID'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_ID', 'event_ID');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'ticket_ID', 'ticket_ID');
    }
}
