<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Core\Http\Exceptions\ApiExceptionRenderer;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Apply the "api" rate limiter (AppServiceProvider) to the api group (§6.13).
        $middleware->throttleApi();

        // API-only app: there is no login page. The framework default redirects
        // guests to route('login'), which does not exist here, so a plain browser
        // hit on a protected route crashed with a 500 *inside* the auth middleware
        // — before ApiExceptionRenderer could run. Returning null keeps the
        // AuthenticationException, which the renderer maps to the 401 envelope.
        $middleware->redirectGuestsTo(fn (): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render the standard API error envelope for API requests (§7).
        $exceptions->render(function (Throwable $e, Request $request) {
            return ApiExceptionRenderer::render($e, $request);
        });
    })->create();
