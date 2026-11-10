<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A user account. The role is either 'admin' or 'attendee'.
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

    /** Our password column is password_hash, not Laravel's default "password", so we tell Laravel here. */
    protected $authPasswordName = 'password_hash';

    /** We don't have a "remember me" feature, so there's no column for it. */
    public function getRememberTokenName()
    {
        return '';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** A user can have many reservations. */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'user_ID', 'user_ID');
    }

    /** An admin can create many events. */
    public function createdEvents(): HasMany
    {
        return $this->hasMany(Event::class, 'user_ID', 'user_ID');
    }
}
