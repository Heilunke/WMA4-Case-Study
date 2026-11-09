<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An error that maps onto an HTTP status and the JSON body {"error":{"message","code"[,"fields"]}}.
 * Rendered in bootstrap/app.php. Named constructors for each HTTP meaning
 * (validation 422, unauthorized 401, forbidden 403, notFound 404, conflict 409 → soldOut 409).
 */
class ApiException extends RuntimeException
{
    /** @param array<string,string> $fields */
    public function __construct(public readonly int $status, string $message, public readonly string $errorCode = 'error', public readonly array $fields = [])
    {
        parent::__construct($message);
    }

    /** @param array<string,string> $fields */
    public static function validation(string $message, array $fields = []): self
    {
        return new self(422, $message, 'validation_failed', $fields);
    }

    public static function unauthorized(string $message = 'Please log in to continue.'): self
    {
        return new self(401, $message, 'unauthorized');
    }

    public static function forbidden(string $message = 'You do not have permission to do that.'): self
    {
        return new self(403, $message, 'forbidden');
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self(404, $message, 'not_found');
    }

    public static function conflict(string $message, string $code = 'conflict'): self
    {
        return new self(409, $message, $code);
    }

    public static function soldOut(string $message): self
    {
        return new self(409, $message, 'sold_out');
    }

    /** @return array{error:array<string,mixed>} */
    public function body(): array
    {
        $error = ['message' => $this->getMessage(), 'code' => $this->errorCode];
        if ($this->fields) {
            $error['fields'] = $this->fields;
        }
        return ['error' => $error];
    }
}
