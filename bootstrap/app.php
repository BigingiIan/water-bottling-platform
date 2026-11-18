<?php

use App\Exceptions\ViewAsReadOnlyException;
use App\Http\Middleware\ApplyConfiguredSessionLifetime;
use App\Http\Middleware\BlockWritesWhileImpersonating;
use App\Http\Middleware\EnsureModuleAccess;
use App\Http\Middleware\EnsurePasswordIsSet;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\UpdateLastActiveAt;
use App\Support\ErrorEventRecorder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'module.access' => EnsureModuleAccess::class,
        ]);
        // Trusted proxies come from config/trustedproxy.php (TRUSTED_PROXIES), which the framework's TrustProxies
        // middleware reads on each request; trustProxies(at:) cannot be used here as configuration is not loaded yet.
        $middleware->prependToGroup('web', ApplyConfiguredSessionLifetime::class);
        $middleware->prependToGroup('web', SecurityHeaders::class);
        $middleware->appendToGroup('web', BlockWritesWhileImpersonating::class);
        $middleware->appendToGroup('web', UpdateLastActiveAt::class);
        $middleware->appendToGroup('web', EnsurePasswordIsSet::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if ($request->expectsJson()) {
                return $response;
            }

            // Recording writes to the database; when that is what failed, the page still renders without a reference.
            $recordReference = fn (int $statusCode): ?string => rescue(
                fn (): string => app(ErrorEventRecorder::class)->record($request, $exception, $statusCode),
                null,
                false,
            );

            return match ($response->getStatusCode()) {
                403 => response()->view('errors.403', [
                    'exception' => $exception,
                    'reference' => $exception instanceof ViewAsReadOnlyException ? null : $recordReference(403),
                ], 403),
                404 => response()->view('errors.404', status: 404),
                500 => response()->view('errors.500', [
                    'reference' => $recordReference(500),
                ], 500),
                default => $response,
            };
        });
    })->create();
