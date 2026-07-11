<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/health — liveness + build info in the standard envelope.
 * Doubles as the first proof that the module system and API contract work.
 */
final class HealthController extends ApiController
{
    public function __invoke(): JsonResponse
    {
        return $this->ok([
            'status' => 'ok',
            'service' => 'evotech-core',
            'api_version' => 'v1',
            'environment' => app()->environment(),
        ]);
    }
}
