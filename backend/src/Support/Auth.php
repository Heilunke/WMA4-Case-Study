<?php
declare(strict_types=1);

namespace ETicket\Support;

use ETicket\Core\Config;
use ETicket\Core\Database;
use ETicket\Core\ForbiddenException;
use ETicket\Core\Request;

/**
 * Session-based authentication. The session id lives in an HttpOnly, SameSite=Lax cookie;
 * the user is re-read from the database on every request so role changes and
 * deleted accounts take effect immediately. A per-session CSRF token must be
 * echoed back in the X-CSRF-Token header on every state-changing request.
 */
final class Auth
{
    private static bool $started = false;
    /** @var array<string,mixed>|null|false false = not loaded yet */
    private static array|null|false $user = false;

    public static function start(): void
    {
        if (self::$started || PHP_SAPI === 'cli') {
            return;
        }
        session_name('ETICKETSESS');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => self::secureCookie(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();
        self::$started = true;
    }

    /** @return array{user_ID:int,full_Name:string,email:string,role:string,created_at:string}|null */
    public static function user(): ?array
    {
        if (self::$user !== false) {
            return self::$user;
        }
        self::start();
        $id = $_SESSION['uid'] ?? null;
        self::$user = null;
        if (is_int($id)) {
            $st = Database::pdo()->prepare('SELECT user_ID, full_Name, email, role, created_at, session_version FROM users WHERE user_ID = ?');
            $st->execute([$id]);
            $row = $st->fetch();
            if ($row && (int) $row['session_version'] === ($_SESSION['sv'] ?? null)) {
                unset($row['session_version']);
                $row['user_ID'] = (int) $row['user_ID'];
                self::$user = $row;
            } else {
                self::logout(); // account deleted, or password changed since this session logged in
            }
        }
        return self::$user;
    }

    /** @param array<string,mixed> $user */
    public static function login(array $user): void
    {
        self::start();
        session_regenerate_id(true); // prevents session fixation
        $_SESSION['uid'] = (int) $user['user_ID'];
        $_SESSION['sv'] = self::sessionVersion((int) $user['user_ID']);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        self::$user = false;
    }

    /** Keeps the CURRENT session valid after its own password change (other sessions are logged out). */
    public static function refreshSessionVersion(): void
    {
        self::start();
        if (is_int($_SESSION['uid'] ?? null)) {
            $_SESSION['sv'] = self::sessionVersion($_SESSION['uid']);
            self::$user = false;
        }
    }

    private static function sessionVersion(int $userId): int
    {
        $st = Database::pdo()->prepare('SELECT session_version FROM users WHERE user_ID = ?');
        $st->execute([$userId]);
        return (int) $st->fetchColumn();
    }

    /**
     * SESSION_SECURE=auto (default) marks the cookie Secure whenever the request came over HTTPS
     * (including via a trusted proxy, see TRUST_PROXY). Set it to true in production to force it.
     */
    private static function secureCookie(): bool
    {
        $mode = strtolower((string) Config::get('SESSION_SECURE', 'auto'));
        if (in_array($mode, ['1', 'true', 'yes'], true)) {
            return true;
        }
        if (in_array($mode, ['0', 'false', 'no'], true)) {
            return false;
        }
        return Request::isHttps();
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
            }
            session_destroy();
        }
        self::$user = null;
    }

    public static function csrfToken(): string
    {
        self::start();
        return (string) ($_SESSION['csrf'] ?? '');
    }

    public static function verifyCsrf(Request $request): void
    {
        $sent = $request->header('X-CSRF-Token') ?? '';
        $expected = self::csrfToken();
        if ($expected === '' || !hash_equals($expected, $sent)) {
            throw new ForbiddenException('Your session token is missing or expired. Reload the page and try again.');
        }
    }
}
