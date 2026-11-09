<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\ApiRequest;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\VerifyCsrfHeader;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The JSON API lives under /api with its own session-based middleware group (see below).
        then: function () {
            Route::middleware('eticket')->prefix('api')->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Session cookie for the API, but no Laravel form-CSRF/XSRF cookie: the API uses its own
        // X-CSRF-Token header check (eticket.csrf), which the frontend sends on every change.
        $middleware->group('eticket', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ApiRequest::class,
            SubstituteBindings::class,
        ]);
        $middleware->alias([
            'eticket.auth' => Authenticate::class,
            'eticket.csrf' => VerifyCsrfHeader::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every /api error is JSON: {"error":{"message","code"}} with the right HTTP status.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (!$request->is('api', 'api/*')) {
                return null;
            }
            [$status, $body] = match (true) {
                $e instanceof ApiException                  => [$e->status, $e->body()],
                $e instanceof NotFoundHttpException         => [404, ['error' => ['message' => 'Endpoint not found.', 'code' => 'not_found']]],
                $e instanceof MethodNotAllowedHttpException => [405, ['error' => ['message' => 'Method not allowed.', 'code' => 'method_not_allowed']]],
                $e instanceof QueryException                => [500, ['error' => ['message' => config('app.debug') ? $e->getMessage() : 'A database error occurred. Please try again.', 'code' => 'database_error']]],
                default                                     => [500, ['error' => ['message' => config('app.debug') ? $e->getMessage() : 'Something went wrong on the server.', 'code' => 'server_error']]],
            };
            return response()->json($body, $status, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        });
        // Expected API errors (4xx) are not worth a log entry.
        $exceptions->dontReport([ApiException::class]);
    })->create();
