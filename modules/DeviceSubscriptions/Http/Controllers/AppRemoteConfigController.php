<?php

namespace Modules\DeviceSubscriptions\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\DeviceSubscriptions\Application\Services\DeviceRemoteConfigBuilder;
use Modules\DeviceSubscriptions\Domain\Models\DeviceApp;

/**
 * GET /api/{slug}/remote-config — the startup config a shipped app fetches.
 *
 * Public and unauthenticated: the app calls this before it has a base URL, let
 * alone a token. It carries nothing sensitive — a version, public download links,
 * and the support channels already printed inside the app.
 *
 * Returned **bare**, with no `{data}` envelope and no `success` flag, because the
 * shipped parsers read the top-level keys directly. This is the one place in the
 * module where the platform envelope is deliberately not used.
 */
final class AppRemoteConfigController
{
    public function __construct(private readonly DeviceRemoteConfigBuilder $builder) {}

    public function __invoke(string $app): JsonResponse
    {
        $deviceApp = DeviceApp::query()
            ->whereRaw('LOWER(slug) = ?', [mb_strtolower($app)])
            ->first();

        if ($deviceApp === null) {
            return response()->json(['message' => 'Unknown app.'], 404);
        }

        return response()->json($this->builder->build($deviceApp));
    }
}
