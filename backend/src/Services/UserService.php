<?php
declare(strict_types=1);

namespace ETicket\Services;

use ETicket\Core\Config;
use ETicket\Core\ConflictException;
use ETicket\Core\Database;
use ETicket\Core\HttpException;
use ETicket\Core\NotFoundException;
use ETicket\Core\ValidationException;
use ETicket\Support\Format;
use ETicket\Support\RateLimiter;
use ETicket\Support\Validator;
use PDO;
use PDOException;

/** Accounts: registration (attendee only), authentication and role management. */
final class UserService
{
    /**
     * Public registration. The role is NEVER read from the request – panel comment #3.
     * Administrators are seeded or promoted by an existing Administrator.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function register(array $in): array
    {
        $name = Validator::string($in['full_Name'] ?? null, 'Full name', 1, 100);
        $email = Validator::email($in['email'] ?? null);
        $password = Validator::password($in['password'] ?? null);
        return $this->create($name, $email, $password, 'attendee');
    }

    /** @return array<string,mixed> */
    public function create(string $name, string $email, string $password, string $role): array
    {
        $pdo = Database::pdo();
        try {
            $pdo->prepare('INSERT INTO users (full_Name, email, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, Format::nowUtc()]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) { // duplicate key on uq_users_email
                throw new ConflictException('That email is already registered.', 'email_taken');
            }
            throw $e;
        }
        return $this->find((int) $pdo->lastInsertId());
    }

    /**
     * Verifies credentials. The same message is used for "no such user" and "wrong password"
     * and a dummy hash is checked for unknown emails so response time does not reveal which.
     *
     * @return array<string,mixed>
     */
    public function authenticate(mixed $email, mixed $password, string $ip = '0.0.0.0'): array
    {
        $fail = new HttpException(401, 'Incorrect email or password.', 'bad_credentials');
        if (!is_string($email) || !is_string($password)) {
            throw $fail;
        }
        $email = strtolower(trim($email));
        RateLimiter::guard('login', $email, $ip); // 429 after too many failures

        $st = Database::pdo()->prepare('SELECT * FROM users WHERE email = ?');
        $st->execute([$email]);
        $row = $st->fetch();
        if (!$row) {
            password_hash($password, PASSWORD_DEFAULT); // burn comparable time for unknown emails
            RateLimiter::fail('login', $email, $ip);
            throw $fail;
        }
        if (!password_verify($password, $row['password_hash'])) {
            RateLimiter::fail('login', $email, $ip);
            throw $fail;
        }
        RateLimiter::clear('login', $email, $ip);
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            Database::pdo()->prepare('UPDATE users SET password_hash = ? WHERE user_ID = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $row['user_ID']]);
        }
        return Format::user($row);
    }

    /* ---------------- password reset (no e-mail server: the code is handed over by an Administrator) ---------------- */

    private const RESET_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no I, O, 0, 1

    /**
     * An Administrator issues a one-time reset code for a user and gives it to them in person
     * (or by phone/chat). Only its SHA-256 hash is stored; issuing a new code replaces the old one.
     *
     * @return array{code:string,expires_at:string}
     */
    public function issueResetCode(int $userId): array
    {
        $this->find($userId); // 404 for unknown users
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code .= self::RESET_ALPHABET[random_int(0, strlen(self::RESET_ALPHABET) - 1)];
        }
        $minutes = max(5, (int) Config::get('PASSWORD_RESET_MINUTES', '30'));
        $expires = gmdate('Y-m-d H:i:s', time() + $minutes * 60);
        Database::pdo()->prepare('UPDATE users SET reset_code_hash = ?, reset_expires_at = ? WHERE user_ID = ?')
            ->execute([hash('sha256', $code), $expires, $userId]);
        return ['code' => $code, 'expires_at' => Format::iso($expires)];
    }

    /**
     * Public: sets a new password using an email + valid reset code. Rate-limited like login.
     * The code is single-use, and every existing session of that account is logged out.
     *
     * @param array<string,mixed> $in {email, code, password}
     */
    public function resetPassword(array $in, string $ip): void
    {
        $email = Validator::email($in['email'] ?? null);
        $password = Validator::password($in['password'] ?? null);
        $code = strtoupper(preg_replace('/[\s-]+/', '', is_string($in['code'] ?? null) ? $in['code'] : ''));
        RateLimiter::guard('reset', $email, $ip);

        $fail = new ValidationException('The reset code is incorrect or has expired. Ask an Administrator for a new one.');
        $ok = Database::transaction(function (PDO $db) use ($email, $code, $password) {
            $st = $db->prepare('SELECT user_ID, reset_code_hash, reset_expires_at FROM users WHERE email = ? FOR UPDATE');
            $st->execute([$email]);
            $u = $st->fetch();
            if (!$u || $u['reset_code_hash'] === null || $code === ''
                || $u['reset_expires_at'] < Format::nowUtc()
                || !hash_equals($u['reset_code_hash'], hash('sha256', $code))) {
                return false;
            }
            $db->prepare('UPDATE users SET password_hash = ?, reset_code_hash = NULL, reset_expires_at = NULL, session_version = session_version + 1 WHERE user_ID = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $u['user_ID']]);
            return true;
        });
        if (!$ok) {
            RateLimiter::fail('reset', $email, $ip);
            throw $fail;
        }
        RateLimiter::clear('reset', $email, $ip);
        RateLimiter::clear('login', $email, $ip); // a fresh password unlocks the login too
    }

    /**
     * A logged-in user changes their own password. Other sessions of the account are logged out;
     * the caller refreshes the current session's version so it stays logged in.
     *
     * @param array<string,mixed> $in {current_password, password}
     */
    public function changePassword(int $userId, array $in): void
    {
        $current = $in['current_password'] ?? null;
        $password = Validator::password($in['password'] ?? null);
        $st = Database::pdo()->prepare('SELECT password_hash FROM users WHERE user_ID = ?');
        $st->execute([$userId]);
        $hash = $st->fetchColumn();
        if (!is_string($current) || !is_string($hash) || !password_verify($current, $hash)) {
            throw new ValidationException('Your current password is incorrect.');
        }
        Database::pdo()->prepare('UPDATE users SET password_hash = ?, reset_code_hash = NULL, reset_expires_at = NULL, session_version = session_version + 1 WHERE user_ID = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
    }

    /** @return array<string,mixed> */
    public function find(int $id): array
    {
        $st = Database::pdo()->prepare('SELECT user_ID, full_Name, email, role, created_at FROM users WHERE user_ID = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            throw new NotFoundException('User not found.');
        }
        return Format::user($row);
    }

    /** @return array<string,mixed> */
    public function setRole(int $userId, mixed $role): array
    {
        $role = Validator::role($role);
        return Database::transaction(function (PDO $db) use ($userId, $role) {
            $st = $db->prepare('SELECT user_ID, role FROM users WHERE user_ID = ? FOR UPDATE');
            $st->execute([$userId]);
            $user = $st->fetch();
            if (!$user) {
                throw new NotFoundException('User not found.');
            }
            if ($user['role'] === 'admin' && $role !== 'admin') {
                $admins = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' FOR UPDATE")->fetchColumn();
                if ($admins < 2) {
                    throw new ValidationException('At least one Administrator is required.');
                }
            }
            $db->prepare('UPDATE users SET role = ? WHERE user_ID = ?')->execute([$role, $userId]);
            return $this->find($userId);
        });
    }
}
