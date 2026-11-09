<?php
declare(strict_types=1);

namespace ETicket\Core;

/** Reads settings from backend/.env (KEY=value lines) and falls back to real environment variables. */
final class Config
{
    /** @var array<string,string> */
    private static array $values = [];

    public static function load(string $envFile): void
    {
        if (!is_file($envFile)) {
            return;
        }
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            self::$values[trim($key)] = trim(trim($value), "\"'");
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (isset(self::$values[$key])) {
            return self::$values[$key];
        }
        $env = getenv($key);
        return $env !== false ? $env : $default;
    }

    public static function debug(): bool
    {
        return in_array(strtolower((string) self::get('APP_DEBUG', 'false')), ['1', 'true', 'yes'], true);
    }
}
