<?php

namespace App\Support;

use App\Exceptions\ApiException;
use DateTimeImmutable;

/**
 * Small server-side validation helpers with user-facing messages.
 * Every one throws ApiException::validation (HTTP 422).
 */
final class Validator
{
    public static function string(mixed $v, string $label, int $min, int $max): string
    {
        if (!is_string($v)) {
            throw ApiException::validation("$label is required.", [$label => 'required']);
        }
        $v = trim($v);
        $len = mb_strlen($v);
        if ($len < $min) {
            throw ApiException::validation($min === 1 ? "$label is required." : "$label must be at least $min characters.");
        }
        if ($len > $max) {
            throw ApiException::validation("$label must be at most $max characters.");
        }
        return $v;
    }

    public static function optionalString(mixed $v, string $label, int $max): string
    {
        return ($v === null || $v === '') ? '' : self::string($v, $label, 0, $max);
    }

    public static function email(mixed $v): string
    {
        $v = self::string($v, 'Email', 1, 100);
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
            throw ApiException::validation('Enter a valid email address.');
        }
        return strtolower($v);
    }

    public static function password(mixed $v): string
    {
        if (!is_string($v) || strlen($v) < 6) {
            throw ApiException::validation('Password must be at least 6 characters.');
        }
        if (strlen($v) > 72) { // bcrypt only uses the first 72 bytes
            throw ApiException::validation('Password must be at most 72 characters.');
        }
        return $v;
    }

    public static function int(mixed $v, string $label, int $min, int $max): int
    {
        if (is_string($v) && preg_match('/^-?\d+$/', $v)) {
            $v = (int) $v;
        }
        if (!is_int($v) || $v < $min || $v > $max) {
            throw ApiException::validation("$label must be a whole number from $min to $max.");
        }
        return $v;
    }

    public static function money(mixed $v, string $label): float
    {
        if (is_string($v) && is_numeric($v)) {
            $v = (float) $v;
        }
        if ((!is_int($v) && !is_float($v)) || $v < 0 || $v > 999999.99) {
            throw ApiException::validation("$label must be a number from 0 to 999999.99.");
        }
        return round((float) $v, 2);
    }

    public static function date(mixed $v, string $label): string
    {
        $d = is_string($v) ? DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
        if (!$d || $d->format('Y-m-d') !== $v) {
            throw ApiException::validation("$label must be a valid date (YYYY-MM-DD).");
        }
        return $v;
    }

    /** @return string HH:MM:SS */
    public static function time(mixed $v, string $label): string
    {
        if (!is_string($v) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $v)) {
            throw ApiException::validation("$label must be a valid time (HH:MM).");
        }
        return strlen($v) === 5 ? $v . ':00' : $v;
    }

    public static function role(mixed $v): string
    {
        if (!in_array($v, ['admin', 'attendee'], true)) {
            throw ApiException::validation('Role must be admin or attendee.');
        }
        return $v;
    }

    public static function id(mixed $v): int
    {
        return self::int($v, 'id', 1, PHP_INT_MAX);
    }
}
