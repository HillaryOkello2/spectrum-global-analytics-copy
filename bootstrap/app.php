<?php

use App\Exceptions\Domain\DomainException;
use App\Http\Middleware\RestrictToApi;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Throwable;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, so it runs before routing and covers package-registered
        // routes (Horizon, Scribe, storage) as well as our own.
        $middleware->append(RestrictToApi::class);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (DomainException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode(),
                ...($e->context() !== [] ? ['meta' => $e->context()] : []),
            ], $e->status());
        });

        // Defence in depth behind RestrictToApi. That middleware stops non-API
        // requests before routing, but middleware EARLIER in the global stack can
        // throw first — ValidatePathEncoding on a malformed URL, ValidatePostSize,
        // PreventRequestsDuringMaintenance — and those would render Laravel's HTML
        // error page, which identifies the framework. Registered last so the
        // DomainException handler above still wins for API errors.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! config('security.api_only') || $request->is('api/*') || $request->expectsJson()) {
                return null; // Normal handling — API errors must stay intact.
            }

            return RestrictToApi::blocked();
        });
    })->create();
