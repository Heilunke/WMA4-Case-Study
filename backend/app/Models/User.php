<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * An account: role 'admin' (Administrator) or 'attendee'.
 *
 * @property int $user_ID
 * @property string $full_Name
 * @property string $email
 * @property string $password_hash
 * @property string $role
 * @property int $session_version
 */
class User extends Authenticatable
{
    protected $table = 'users';
    protected $primaryKey = 'user_ID';
    public $timestamps = false;
    protected $guarded = ['user_ID'];
    protected $hidden = ['password_hash', 'reset_code_hash', 'reset_expires_at', 'session_version'];

    /** Laravel's Auth reads the hash from this column instead of the default "password". */
    protected $authPasswordName = 'password_hash';

    /** No "remember me" column in this schema. */
    public function getRememberTokenName()
    {
        return '';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** One user has many reservations (as the attendee who booked). */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'user_ID', 'user_ID');
    }

    /** One Administrator creates many events. */
    public function createdEvents(): HasMany
    {
        return $this->hasMany(Event::class, 'user_ID', 'user_ID');
    }
}
