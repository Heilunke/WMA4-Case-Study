<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Format;
use App\Support\Validator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/** Accounts: registration (attendee only), authentication, password reset/change and roles. */
class UserService
{
    private const RESET_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no I, O, 0, 1

    /** Public registration. The role is NEVER read from the request (panel comment #3). */
    public function register(array $in): array
    {
        $name = Validator::string($in['full_Name'] ?? null, 'Full name', 1, 100);
        $email = Validator::email($in['email'] ?? null);
        $password = Validator::password($in['password'] ?? null);
        return $this->create($name, $email, $password, 'attendee');
    }

    public function create(string $name, string $email, string $password, string $role): array
    {
        try {
            $user = User::create([
                'full_Name'     => $name,
                'email'         => $email,
                'password_hash' => Hash::make($password),
                'role'          => $role,
                'created_at'    => Format::nowUtc(),
            ]);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) { // duplicate key on uq_users_email
                throw ApiException::conflict('That email is already registered.', 'email_taken');
            }
            throw $e;
        }
        return Format::user($user);
    }

    /**
     * Verifies credentials, rate-limited per email+IP and per IP. "Unknown email" and "wrong password"
     * get the same message and comparable time.
     */
    public function authenticate(mixed $email, mixed $password, string $ip): User
    {
        $fail = new ApiException(401, 'Incorrect email or password.', 'bad_credentials');
        if (!is_string($email) || !is_string($password)) {
            throw $fail;
        }
        $email = strtolower(trim($email));
        $this->guard('login', $email, $ip);

        $user = User::where('email', $email)->first();
        if (!$user) {
            Hash::make($password); // burn comparable time for unknown emails
            $this->fail('login', $email, $ip);
            throw $fail;
        }
        if (!Hash::check($password, $user->password_hash)) {
            $this->fail('login', $email, $ip);
            throw $fail;
        }
        RateLimiter::clear($this->key('login', $email, $ip));
        if (Hash::needsRehash($user->password_hash)) {
            $user->forceFill(['password_hash' => Hash::make($password)])->save();
        }
        return $user;
    }

    /* ---------------- password reset (no e-mail server: an Administrator hands over the code) ---------------- */

    /** @return array{code:string,expires_at:string} */
    public function issueResetCode(int $userId): array
    {
        $user = $this->model($userId);
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code .= self::RESET_ALPHABET[random_int(0, strlen(self::RESET_ALPHABET) - 1)];
        }
        $expires = gmdate('Y-m-d H:i:s', time() + max(5, config('eticket.password_reset_minutes')) * 60);
        $user->forceFill(['reset_code_hash' => hash('sha256', $code), 'reset_expires_at' => $expires])->save();
        return ['code' => $code, 'expires_at' => Format::iso($expires)];
    }

    /** Public: new password with email + valid code. Single-use; logs out every session of the account. */
    public function resetPassword(array $in, string $ip): void
    {
        $email = Validator::email($in['email'] ?? null);
        $password = Validator::password($in['password'] ?? null);
        $code = strtoupper(preg_replace('/[\s-]+/', '', is_string($in['code'] ?? null) ? $in['code'] : ''));
        $this->guard('reset', $email, $ip);

        $ok = DB::transaction(function () use ($email, $code, $password) {
            $user = User::where('email', $email)->lockForUpdate()->first();
            if (!$user || $user->reset_code_hash === null || $code === ''
                || $user->reset_expires_at < Format::nowUtc()
                || !hash_equals($user->reset_code_hash, hash('sha256', $code))) {
                return false;
            }
            $user->forceFill([
                'password_hash'    => Hash::make($password),
                'reset_code_hash'  => null,
                'reset_expires_at' => null,
                'session_version'  => $user->session_version + 1,
            ])->save();
            return true;
        });
        if (!$ok) {
            $this->fail('reset', $email, $ip);
            throw ApiException::validation('The reset code is incorrect or has expired. Ask an Administrator for a new one.');
        }
        RateLimiter::clear($this->key('reset', $email, $ip));
        RateLimiter::clear($this->key('login', $email, $ip)); // a fresh password unlocks the login too
    }

    /** A logged-in user changes their own password; other sessions are logged out. */
    public function changePassword(int $userId, array $in): void
    {
        $current = $in['current_password'] ?? null;
        $password = Validator::password($in['password'] ?? null);
        $user = $this->model($userId);
        if (!is_string($current) || !Hash::check($current, $user->password_hash)) {
            throw ApiException::validation('Your current password is incorrect.');
        }
        $user->forceFill([
            'password_hash'    => Hash::make($password),
            'reset_code_hash'  => null,
            'reset_expires_at' => null,
            'session_version'  => $user->session_version + 1,
        ])->save();
    }

    public function find(int $id): array
    {
        return Format::user($this->model($id));
    }

    public function setRole(int $userId, mixed $role): array
    {
        $role = Validator::role($role);
        return DB::transaction(function () use ($userId, $role) {
            $user = User::lockForUpdate()->find($userId) ?? throw ApiException::notFound('User not found.');
            if ($user->role === 'admin' && $role !== 'admin') {
                $admins = User::where('role', 'admin')->lockForUpdate()->count();
                if ($admins < 2) {
                    throw ApiException::validation('At least one Administrator is required.');
                }
            }
            $user->forceFill(['role' => $role])->save();
            return Format::user($user);
        });
    }

    private function model(int $id): User
    {
        return User::find($id) ?? throw ApiException::notFound('User not found.');
    }

    /* ---------------- throttling with Laravel's RateLimiter (file cache) ---------------- */

    private function key(string $scope, string $email, string $ip): string
    {
        return "$scope|acct|" . hash('sha256', $email) . "|$ip";
    }

    private function guard(string $scope, string $email, string $ip): void
    {
        if (RateLimiter::tooManyAttempts($this->key($scope, $email, $ip), config('eticket.login_max_per_account'))
            || RateLimiter::tooManyAttempts("$scope|ip|$ip", config('eticket.login_max_per_ip'))) {
            throw new ApiException(429, sprintf('Too many failed attempts. Please wait %d minutes and try again.',
                config('eticket.login_window_minutes')), 'too_many_attempts');
        }
    }

    private function fail(string $scope, string $email, string $ip): void
    {
        $decay = config('eticket.login_window_minutes') * 60;
        RateLimiter::hit($this->key($scope, $email, $ip), $decay);
        RateLimiter::hit("$scope|ip|$ip", $decay);
    }
}
