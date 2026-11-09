<?php
declare(strict_types=1);

namespace ETicket\Core;

use PDO;
use PDOException;
use Throwable;

/** Single PDO connection (prepared statements only) plus a transaction helper. */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                Config::get('DB_HOST', '127.0.0.1'),
                Config::get('DB_PORT', '3306'),
                Config::get('DB_NAME', 'eticket')
            );
            self::$pdo = new PDO($dsn, Config::get('DB_USER', 'root'), Config::get('DB_PASS', ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'",
            ]);
        }
        return self::$pdo;
    }

    /** For tests / setup scripts that need to reconnect to a different database. */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    /**
     * Runs $work inside a transaction. Rolls back on any exception and retries
     * up to 3 times when MySQL reports a deadlock (1213) or lock wait timeout (1205).
     *
     * @template T
     * @param callable(PDO):T $work
     * @return T
     */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::pdo();
        for ($attempt = 1;; $attempt++) {
            $pdo->beginTransaction();
            try {
                $result = $work($pdo);
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $retryable = $e instanceof PDOException && in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true);
                if (!$retryable || $attempt >= 3) {
                    throw $e;
                }
            }
        }
    }
}
