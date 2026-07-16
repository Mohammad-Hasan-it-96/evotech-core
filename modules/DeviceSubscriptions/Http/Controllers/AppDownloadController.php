<?php

namespace Modules\DeviceSubscriptions\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * GET app-download — app version + APK links (ADR 0010).
 *
 * The legacy backend served an HTML landing page sourced from a Google Drive JSON.
 * This API repo returns JSON only (no Blade UI — see CLAUDE.md); the human-facing
 * download page belongs in evotech-web. Sourcing the live version/links from the
 * Download Center (ADR 0008) instead of Google Drive is a documented follow-up.
 */
final class AppDownloadController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'latest_version' => config('device-subscriptions.download.latest_version'),
            'downloads' => config('device-subscriptions.download.links', []),
        ]);
    }
}
