<?php
declare(strict_types=1);

namespace ETicket\Core;

use ETicket\Support\Auth;

/**
 * Minimal router. Routes are declared with an access level:
 *   'public'   – no login needed (login / register)
 *   'user'     – any logged-in user
 *   'admin' / 'attendee' – a specific role (RBAC)
 * Every unsafe (non-GET) request on a protected route must carry the CSRF token.
 */
final class Router
{
    /** @var list<array{method:string,regex:string,handler:callable,access:string}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, string $access = 'user'): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler, 'access' => $access];
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

            if ($route['access'] !== 'public') {
                $user = Auth::user();
                if ($user === null) {
                    throw new UnauthorizedException();
                }
                if (in_array($route['access'], ['admin', 'attendee'], true) && $user['role'] !== $route['access']) {
                    throw new ForbiddenException();
                }
                if ($request->isUnsafe()) {
                    Auth::verifyCsrf($request);
                }
            }
            $result = ($route['handler'])($request, $params);
            return $result instanceof Response ? $result : new Response($result);
        }
        if ($pathMatched) {
            throw new HttpException(405, 'Method not allowed.', 'method_not_allowed');
        }
        throw new NotFoundException('Endpoint not found.');
    }
}
