<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Http\Responses\ApiResponse;

/**
 * Base controller for API endpoints. Keeps controllers thin: they validate
 * input (Form Requests), call one Application service, and return an envelope.
 */
abstract class ApiController
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $links
     */
    protected function ok(mixed $data = null, array $meta = [], array $links = []): JsonResponse
    {
        return ApiResponse::success($data, $meta, $links);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function created(mixed $data = null, array $meta = []): JsonResponse
    {
        return ApiResponse::success($data, $meta, status: 201);
    }

    protected function noContent(): JsonResponse
    {
        return ApiResponse::noContent();
    }
}
