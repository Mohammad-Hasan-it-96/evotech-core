<?php

namespace Modules\DeviceSubscriptions\Infrastructure\Push;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\DeviceSubscriptions\Application\Services\DeviceAppCatalog;
use Modules\DeviceSubscriptions\Domain\Contracts\DevicePushNotifier;
use Throwable;

/**
 * Firebase Cloud Messaging over the HTTP v1 API (ADR 0010).
 *
 * The payload mirrors the legacy backend's exactly — the shipped SmartAgent app
 * reads `data.type` and `notification.title/body`, and any change there is a
 * change to a contract we cannot redeploy. The one addition is
 * `android.priority=high`: activation is a live unlock the user is waiting on,
 * so it must not be batched by Doze.
 *
 * Deliberately NOT sent: `android.notification.channel_id`. SmartAgent already
 * routes to `subscription_channel` via a manifest default, while Fawateer
 * declares no channel at all — and naming a channel that does not exist gets the
 * notification dropped by Android, so being explicit here would break one app to
 * decorate the other.
 *
 * Failures are logged and swallowed. A push is a convenience: the apps re-check
 * `check_device` on resume, so a lost notification delays an unlock but never
 * loses it — whereas throwing here would fail the operator's activation request
 * after the subscription has already been committed.
 */
final class FirebasePushNotifier implements DevicePushNotifier
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** Google mints 1-hour tokens; refresh a little early to avoid a race at expiry. */
    private const TOKEN_TTL_SECONDS = 3300;

    public function __construct(private readonly DeviceAppCatalog $apps) {}

    public function send(string $appName, string $token, string $title, string $body, string $type): void
    {
        $firebase = $this->apps->firebase($appName);

        if ($firebase === null) {
            Log::warning('FCM send skipped: no Firebase credential for this app.', [
                'app' => $appName,
                'type' => $type,
            ]);

            return;
        }

        $accessToken = $this->accessToken($appName, $firebase['credentials']);

        if ($accessToken === null) {
            return;
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post("https://fcm.googleapis.com/v1/projects/{$firebase['project_id']}/messages:send", [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'data' => [
                            'type' => $type,
                        ],
                        'android' => [
                            'priority' => 'high',
                        ],
                    ],
                ]);
        } catch (Throwable $e) {
            Log::error('FCM send failed: transport error.', [
                'app' => $appName,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($response->successful()) {
            return;
        }

        // FCM reports a dead token as 404 UNREGISTERED / 400 INVALID_ARGUMENT.
        // Logged rather than auto-cleared: pruning tokens is a data change that
        // belongs to the service layer, not to the transport.
        Log::warning('FCM send rejected.', [
            'app' => $appName,
            'type' => $type,
            'status' => $response->status(),
            'fcm_status' => $response->json('error.status'),
            'fcm_message' => $response->json('error.message'),
        ]);
    }

    /**
     * A cached OAuth2 bearer token for the app's service account.
     *
     * Cached per app because the expiry sweep sends to many devices in one run —
     * minting a fresh token per message (as the legacy backend does) turns one
     * signed JWT exchange into hundreds.
     */
    private function accessToken(string $appName, string $credentials): ?string
    {
        try {
            return Cache::remember(
                "device-subscriptions:fcm-token:{$appName}",
                self::TOKEN_TTL_SECONDS,
                function () use ($credentials): string {
                    $account = new ServiceAccountCredentials(self::SCOPE, $this->keyFor($credentials));
                    $token = $account->fetchAuthToken()['access_token'] ?? null;

                    if (! is_string($token) || $token === '') {
                        throw new \RuntimeException('Google returned no access token.');
                    }

                    return $token;
                }
            );
        } catch (Throwable $e) {
            Log::error('FCM auth failed: could not mint an access token.', [
                'app' => $appName,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Accepts either a path to the service-account JSON or its literal contents,
     * so a deployment that cannot write a file (containers, CI) can pass the key
     * through the environment instead.
     *
     * @return string|array<mixed, mixed>
     */
    private function keyFor(string $credentials): string|array
    {
        if (str_starts_with(ltrim($credentials), '{')) {
            $decoded = json_decode($credentials, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $credentials;
    }
}
