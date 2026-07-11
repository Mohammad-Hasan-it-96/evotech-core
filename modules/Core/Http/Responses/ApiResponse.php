<?php

namespace Modules\Core\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Builds the platform's standard API envelopes (constitution §7).
 *
 * Success: { "data": ..., "meta"?: {...}, "links"?: {...} }
 * Error:   { "error": { "code", "message", "details", "trace_id" } }
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $links
     */
    public static function success(
        mixed $data = null,
        array $meta = [],
        array $links = [],
        int $status = 200,
    ): JsonResponse {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        if ($links !== []) {
            $payload['links'] = $links;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param  list<array<string, mixed>>  $details
     */
    public static function error(
        string $code,
        string $message,
        array $details = [],
        int $status = 400,
        ?string $traceId = null,
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'trace_id' => $traceId ?? (string) Str::uuid(),
            ],
        ], $status);
    }

    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }
}
