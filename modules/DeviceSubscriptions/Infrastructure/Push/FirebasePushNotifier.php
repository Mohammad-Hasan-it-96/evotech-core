<?php

namespace Modules\DeviceSubscriptions\Infrastructure\Push;

use Illuminate\Support\Facades\Log;
use Modules\DeviceSubscriptions\Domain\Contracts\DevicePushNotifier;

/**
 * Firebase Cloud Messaging notifier — scaffold pending credentials (ADR 0010),
 * mirroring how the Stripe gateway is staged (ADR 0009). Wire the FCM HTTP v1
 * send here once FIREBASE_PROJECT_ID + FIREBASE_CREDENTIALS are provisioned; until
 * then it logs and no-ops so nothing throws when misconfigured.
 */
final class FirebasePushNotifier implements DevicePushNotifier
{
    public function send(string $token, string $title, string $body, string $type): void
    {
        $projectId = config('device-subscriptions.firebase.project_id');
        $credentials = config('device-subscriptions.firebase.credentials');

        if (empty($projectId) || empty($credentials)) {
            Log::warning('FCM send skipped: Firebase not configured.', ['type' => $type]);

            return;
        }

        // TODO(ADR 0010): obtain an OAuth2 access token from the service-account
        // credentials and POST to https://fcm.googleapis.com/v1/projects/{id}/messages:send
        // with {message:{token, notification:{title, body}, data:{type}}}.
        Log::info('FCM send (not yet implemented).', [
            'type' => $type,
            'title' => $title,
        ]);
    }
}
