<?php
declare(strict_types=1);

namespace ETicket\Core;

/** The incoming HTTP request: method, API-relative path, JSON body and headers. */
final class Request
{
    private const MAX_BODY_BYTES = 200_000;

    /** @param array<string,mixed> $body @param array<string,string> $headers @param array<string,mixed> $query */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        private readonly array $headers
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        // Works for /api/..., /index.php/api/... and /backend/public/index.php/api/...
        $pos = strpos($uri, '/api/');
        if ($pos !== false) {
            $path = substr($uri, $pos + 4);
        } elseif (str_ends_with($uri, '/api')) {
            $path = '/';
        } else {
            $path = $uri;
        }
        $path = '/' . trim($path, '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }

        $body = [];
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $raw = file_get_contents('php://input') ?: '';
            if (strlen($raw) > self::MAX_BODY_BYTES) {
                throw new HttpException(413, 'Request body is too large.', 'payload_too_large');
            }
            if (trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    throw new HttpException(400, 'The request body must be valid JSON.', 'bad_json');
                }
                $body = $decoded;
            }
        }
        return new self($method, $path, $_GET, $body, $headers);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isUnsafe(): bool
    {
        return !in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * The caller's IP. X-Forwarded-For is honoured only when TRUST_PROXY=true (i.e. the app sits
     * behind a reverse proxy you control); otherwise a client could spoof it to dodge rate limits.
     */
    public static function clientIp(): string
    {
        if (self::trustProxy() && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** True when the browser reached us over HTTPS (directly, or via a trusted proxy that terminated TLS). */
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        return self::trustProxy() && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    private static function trustProxy(): bool
    {
        return in_array(strtolower((string) Config::get('TRUST_PROXY', 'false')), ['1', 'true', 'yes'], true);
    }
}
