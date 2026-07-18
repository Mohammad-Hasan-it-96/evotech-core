<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\DeviceSubscriptions\Infrastructure\Push\FirebasePushNotifier;
use Tests\TestCase;

/**
 * The FCM v1 sender.
 *
 * Each test seeds the cached OAuth token so the service-account JWT exchange is
 * skipped: minting one needs a real private key and a call to Google, and what
 * is worth pinning here is the request we build, not Google's auth library.
 */
class FirebasePushNotifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'device-subscriptions.apps.Fawateer.firebase' => [
                'project_id' => 'fawateer-4c9bc',
                'credentials' => '/secrets/fawateer.json',
            ],
            'device-subscriptions.apps.SmartAgent.firebase' => [
                'project_id' => 'smart-agent-5b153',
                'credentials' => '/secrets/smartagent.json',
            ],
        ]);

        Cache::put('device-subscriptions:fcm-token:Fawateer', 'fake-access-token', 60);
        Cache::put('device-subscriptions:fcm-token:SmartAgent', 'fake-access-token', 60);
    }

    private function notifier(): FirebasePushNotifier
    {
        return $this->app->make(FirebasePushNotifier::class);
    }

    public function test_it_posts_the_payload_the_shipped_apps_expect(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/1'])]);

        $this->notifier()->send('Fawateer', 'tok-123', 'عنوان', 'نص', 'new_plan_activated');

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://fcm.googleapis.com/v1/projects/fawateer-4c9bc/messages:send'
                && $request->hasHeader('Authorization', 'Bearer fake-access-token')
                && data_get($body, 'message.token') === 'tok-123'
                && data_get($body, 'message.notification.title') === 'عنوان'
                && data_get($body, 'message.notification.body') === 'نص'
                && data_get($body, 'message.data.type') === 'new_plan_activated'
                && data_get($body, 'message.android.priority') === 'high';
        });
    }

    /**
     * The regression this whole per-app design exists for. Fawateer and
     * SmartAgent are separate Firebase projects, so sending one app's token to
     * the other's project fails with 404 UNREGISTERED — silently, from the
     * operator's point of view.
     */
    public function test_each_app_sends_to_its_own_firebase_project(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'ok'])]);

        $this->notifier()->send('SmartAgent', 'tok-sa', 't', 'b', 'new_plan_activated');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'projects/smart-agent-5b153/messages:send'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'fawateer-4c9bc'));
    }

    public function test_it_sends_nothing_when_the_app_has_no_credential(): void
    {
        config(['device-subscriptions.apps.Fawateer.firebase.credentials' => null]);
        Http::fake();

        $this->notifier()->send('Fawateer', 'tok-123', 't', 'b', 'new_plan_activated');

        Http::assertNothingSent();
    }

    public function test_an_unknown_app_sends_nothing(): void
    {
        Http::fake();

        $this->notifier()->send('NotAnApp', 'tok-123', 't', 'b', 'new_plan_activated');

        Http::assertNothingSent();
    }

    /**
     * A rejected push must not surface as an exception: activation has already
     * been committed by the time we send, so throwing here would fail the
     * operator's request over a notification that the app's own check_device
     * poll would have recovered from anyway.
     */
    public function test_a_rejected_send_is_swallowed(): void
    {
        Http::fake([
            'fcm.googleapis.com/*' => Http::response([
                'error' => ['status' => 'UNREGISTERED', 'message' => 'Requested entity was not found.'],
            ], 404),
        ]);

        $this->notifier()->send('Fawateer', 'dead-token', 't', 'b', 'new_plan_activated');

        Http::assertSentCount(1);
    }

    public function test_a_transport_failure_is_swallowed(): void
    {
        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        // The assertion is the absence of a thrown exception: if send() let the
        // transport error escape, this test errors out.
        $this->expectNotToPerformAssertions();

        $this->notifier()->send('Fawateer', 'tok-123', 't', 'b', 'new_plan_activated');
    }
}
