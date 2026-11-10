<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row = one ticket. ticket_code is the e-ticket (the QR), and status tells if they already checked in. */
class AttendeeDetail extends Model
{
    protected $table = 'attendee_details';
    protected $primaryKey = 'attendee_ID';
    public $timestamps = false;
    protected $guarded = ['attendee_ID'];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_ID', 'reservation_ID');
    }

    /** The admin who checked this attendee in at the entrance. */
    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by', 'user_ID');
    }
}
