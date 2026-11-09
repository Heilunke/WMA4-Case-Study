<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The payment record of a reservation (no online gateway: an Administrator records it). */
class Payment extends Model
{
    protected $table = 'payments';
    protected $primaryKey = 'payment_ID';
    public $timestamps = false;
    protected $guarded = ['payment_ID'];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_ID', 'reservation_ID');
    }

    /** The Administrator who recorded the payment. */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by', 'user_ID');
    }
}
