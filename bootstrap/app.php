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

        /*
         * Answer unauthenticated API requests with 401, not 500.
         *
         * `Authenticate::redirectTo()` calls `route('login')` *eagerly*, before the
         * AuthenticationException is constructed. This repo is API-only and has no
         * such route, so that call threw RouteNotFoundException — which reached
         * ApiExceptionRenderer as an unknown error and became a 500. The renderer
         * was never given an auth exception to recognise.
         *
         * It only bit requests that do not send `Accept: application/json`, which
         * is why the dashboard never saw it and a browser or a bare curl always
         * did — the worst shape for a bug, since the clients most likely to hit it
         * are the ones least able to explain it.
         *
         * Returning null leaves the exception intact for the renderer. Non-API
         * requests get `/` rather than null: nothing under `web` is authenticated
         * today, but a null there would fall back to `route('login')` and
         * reintroduce exactly this 500.
         */
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') ? null : '/',
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render the standard API error envelope for API requests (§7).
        $exceptions->render(function (Throwable $e, Request $request) {
            return ApiExceptionRenderer::render($e, $request);
        });
    })->create();
