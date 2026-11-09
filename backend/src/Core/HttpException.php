<?php
declare(strict_types=1);

namespace ETicket\Core;

use RuntimeException;

/** An error that maps directly onto an HTTP status and a JSON error body. */
class HttpException extends RuntimeException
{
    /** @param array<string,string> $fields per-field validation messages */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly string $errorCode = 'error',
        public readonly array $fields = []
    ) {
        parent::__construct($message);
    }
}
