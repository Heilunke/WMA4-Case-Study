<?php
declare(strict_types=1);

/**
 * Domain exceptions (one class per HTTP meaning) so services can say what went
 * wrong without knowing about HTTP, and callers can catch the specific case.
 * Loaded explicitly by bootstrap because PSR-4 autoloading is one class per file.
 */

namespace ETicket\Core;

final class ValidationException extends HttpException
{
    /** @param array<string,string> $fields */
    public function __construct(string $message, array $fields = [])
    {
        parent::__construct(422, $message, 'validation_failed', $fields);
    }
}

final class UnauthorizedException extends HttpException
{
    public function __construct(string $message = 'Please log in to continue.')
    {
        parent::__construct(401, $message, 'unauthorized');
    }
}

final class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'You do not have permission to do that.')
    {
        parent::__construct(403, $message, 'forbidden');
    }
}

final class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Not found.')
    {
        parent::__construct(404, $message, 'not_found');
    }
}

/** The request is valid but conflicts with the current state (e.g. cancelling a checked-in reservation). */
class ConflictException extends HttpException
{
    public function __construct(string $message, string $code = 'conflict')
    {
        parent::__construct(409, $message, $code);
    }
}

/** Not enough tickets left for the requested quantity. */
final class SoldOutException extends ConflictException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'sold_out');
    }
}
