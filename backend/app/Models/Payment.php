<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The payment of a reservation. There's no online payment, the admin just records it here. */
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

    /** The admin who recorded the payment. */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by', 'user_ID');
    }
}
