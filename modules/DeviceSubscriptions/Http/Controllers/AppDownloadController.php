<?php

namespace Modules\DeviceSubscriptions\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\DeviceSubscriptions\Domain\Models\DeviceApp;

/**
 * GET app-download — app version + APK links (ADR 0010).
 *
 * The legacy backend served an HTML landing page sourced from a Google Drive JSON.
 * This API repo returns JSON only (no Blade UI — see CLAUDE.md); the human-facing
 * download page belongs in evotech-web.
 *
 * Reads the same `device_apps` columns as the remote-config endpoint. It used to
 * read a separate pair of env-backed config keys, which meant `latest_version` had
 * two homes that could disagree — and the env one was unset, so this endpoint
 * always answered `null`.
 */
final class AppDownloadController
{
    public function index(?string $app = null): JsonResponse
    {
        $deviceApp = $app === null
            ? null
            : DeviceApp::query()->whereRaw('LOWER(slug) = ?', [mb_strtolower($app)])->first();

        if ($deviceApp !== null) {
            return response()->json([
                'success' => true,
                'latest_version' => $deviceApp->latest_version,
                'downloads' => $deviceApp->downloads ?? [],
            ]);
        }

        /*
         * The un-namespaced surface cannot tell which app is asking, so it keeps
         * answering from config. Unset by default, which is the honest answer:
         * there is no single "latest version" across several products.
         */
        return response()->json([
            'success' => true,
            'latest_version' => config('device-subscriptions.download.latest_version'),
            'downloads' => config('device-subscriptions.download.links', []),
        ]);
    }
}
