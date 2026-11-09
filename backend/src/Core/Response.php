<?php
declare(strict_types=1);

namespace ETicket\Core;

/** A JSON response. */
final class Response
{
    /** @param mixed $data */
    public function __construct(public readonly mixed $data, public readonly int $status = 200)
    {
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        if ($this->status !== 204) {
            echo json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
    }

    public static function error(HttpException $e): self
    {
        $error = ['message' => $e->getMessage(), 'code' => $e->errorCode];
        if ($e->fields) {
            $error['fields'] = $e->fields;
        }
        return new self(['error' => $error], $e->status);
    }
}
