<?php

namespace Modules\Core\Http\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\Responses\ApiResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Maps thrown exceptions to the platform's standard error envelope (§7) for API
 * requests. Returns null for non-API requests so web/default handling applies.
 */
final class ApiExceptionRenderer
{
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => self::validation($e),
            $e instanceof AuthenticationException => ApiResponse::error(
                'UNAUTHENTICATED', __('Unauthenticated.'), status: 401
            ),
            $e instanceof AuthorizationException => ApiResponse::error(
                'FORBIDDEN', $e->getMessage() ?: __('This action is unauthorized.'), status: 403
            ),
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => ApiResponse::error(
                'NOT_FOUND', __('Resource not found.'), status: 404
            ),
            $e instanceof TooManyRequestsHttpException => ApiResponse::error(
                'RATE_LIMITED', __('Too many requests.'), status: 429
            ),
            default => self::generic($e),
        };
    }

    private static function validation(ValidationException $e): JsonResponse
    {
        $details = [];

        foreach ($e->errors() as $field => $messages) {
            if (! is_array($messages)) {
                continue;
            }

            foreach ($messages as $message) {
                if (is_string($message)) {
                    $details[] = ['field' => $field, 'issue' => $message];
                }
            }
        }

        return ApiResponse::error('VALIDATION_FAILED', $e->getMessage(), $details, 422);
    }

    private static function generic(Throwable $e): ?JsonResponse
    {
        // Preserve status for other HTTP exceptions; let non-HTTP errors fall
        // through to the framework (so debug mode still shows the trace).
        if ($e instanceof HttpExceptionInterface) {
            return ApiResponse::error(
                'HTTP_ERROR',
                $e->getMessage() ?: 'HTTP error.',
                status: $e->getStatusCode(),
            );
        }

        if (config('app.debug')) {
            return null;
        }

        return ApiResponse::error('SERVER_ERROR', __('Something went wrong.'), status: 500);
    }
}
