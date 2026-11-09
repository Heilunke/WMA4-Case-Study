<?php
declare(strict_types=1);

namespace ETicket\Support;

use ETicket\Core\Config;
use ETicket\Core\Database;
use ETicket\Core\HttpException;

/**
 * Throttles brute-force attempts on login and password reset.
 *
 * Only FAILED attempts are recorded (table login_attempts). A request is refused with
 * HTTP 429 when, inside the window, there are already
 *   - LOGIN_MAX_PER_ACCOUNT failures for this email from this IP (default 5), or
 *   - LOGIN_MAX_PER_IP failures from this IP across all emails (default 50).
 * A successful login clears that email + IP's failures. Emails are stored as SHA-256 hashes.
 */
final class RateLimiter
{
    public static function windowMinutes(): int
    {
        return max(1, (int) Config::get('LOGIN_WINDOW_MINUTES', '15'));
    }

    /** Throws 429 if this email/IP is currently locked out for $scope ('login' | 'reset'). */
    public static function guard(string $scope, string $email, string $ip): void
    {
        $since = gmdate('Y-m-d H:i:s', time() - self::windowMinutes() * 60);
        $pdo = Database::pdo();

        $perAccount = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE scope = ? AND identifier = ? AND ip = ? AND attempted_at > ?');
        $perAccount->execute([$scope, self::id($email), $ip, $since]);
        $perIp = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE scope = ? AND ip = ? AND attempted_at > ?');
        $perIp->execute([$scope, $ip, $since]);

        if ((int) $perAccount->fetchColumn() >= (int) Config::get('LOGIN_MAX_PER_ACCOUNT', '5')
            || (int) $perIp->fetchColumn() >= (int) Config::get('LOGIN_MAX_PER_IP', '50')) {
            throw new HttpException(429, sprintf(
                'Too many failed attempts. Please wait %d minutes and try again.',
                self::windowMinutes()
            ), 'too_many_attempts');
        }
    }

    public static function fail(string $scope, string $email, string $ip): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO login_attempts (scope, identifier, ip, attempted_at) VALUES (?, ?, ?, ?)')
            ->execute([$scope, self::id($email), $ip, Format::nowUtc()]);
        // Housekeeping: keep the table small.
        $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')
            ->execute([gmdate('Y-m-d H:i:s', time() - 86400)]);
    }

    public static function clear(string $scope, string $email, string $ip): void
    {
        Database::pdo()->prepare('DELETE FROM login_attempts WHERE scope = ? AND identifier = ? AND ip = ?')
            ->execute([$scope, self::id($email), $ip]);
    }

    private static function id(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }
}
