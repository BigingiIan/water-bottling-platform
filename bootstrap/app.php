<?php

use App\Http\Middleware\EnsureModuleAccess;
use App\Http\Middleware\EnsurePasswordIsSet;
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

            return match ($response->getStatusCode()) {
                403 => response()->view('errors.403', [
                    'exception' => $exception,
                    'reference' => app(ErrorEventRecorder::class)->record($request, $exception, 403),
                ], 403),
                404 => response()->view('errors.404', status: 404),
                500 => response()->view('errors.500', [
                    'reference' => app(ErrorEventRecorder::class)->record($request, $exception, 500),
                ], 500),
                default => $response,
            };
        });
    })->create();
