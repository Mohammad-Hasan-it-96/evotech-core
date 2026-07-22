<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\DeviceSubscriptions\Domain\Contracts\DevicePushNotifier;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

/**
 * Custom (ad-hoc) notifications — offers, updates, announcements.
 *
 * The feature exists so an operator can reach the install base without a code
 * deploy, and the two-step (test one device, then broadcast) exists so a message
 * is seen on a real screen before it goes to everyone. A recording notifier stands
 * in for FCM so the tests pin *who* would be pushed to, not Google's transport.
 */
class CustomNotificationTest extends TestCase
{
    use RefreshDatabase;

    private RecordingPushNotifier $push;

    protected function setUp(): void
    {
        parent::setUp();

        $this->push = new RecordingPushNotifier;
        $this->app->instance(DevicePushNotifier::class, $this->push);
    }

    private function actAsStaff(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
    }

    public function test_it_sends_a_test_to_a_single_device(): void
    {
        $this->actAsStaff();
        $device = DeviceSubscription::factory()->create([
            'app_name' => 'SmartAgent',
            'fcm_token' => 'tok-1',
        ]);

        $this->postJson('/api/v1/device-notifications/test', [
            'device' => $device->uuid,
            'title' => 'عرض خاص',
            'body' => 'خصم 20٪ هذا الأسبوع',
        ])->assertCreated()
            ->assertJsonPath('data.scope', 'test')
            ->assertJsonPath('data.recipients', 1);

        $this->assertCount(1, $this->push->sent);
        $this->assertSame('tok-1', $this->push->sent[0]['token']);
        $this->assertSame('custom_message', $this->push->sent[0]['type']);
        $this->assertDatabaseHas('device_notifications', [
            'scope' => 'test',
            'target_device_id' => $device->device_id,
            'recipients' => 1,
        ]);
    }

    public function test_a_test_send_needs_a_push_token(): void
    {
        $this->actAsStaff();
        $device = DeviceSubscription::factory()->create([
            'app_name' => 'SmartAgent',
            'fcm_token' => null,
        ]);

        // No token means nothing to send to — refuse rather than record a phantom
        // dispatch the operator would read as "delivered".
        $this->postJson('/api/v1/device-notifications/test', [
            'device' => $device->uuid,
            'title' => 't',
            'body' => 'b',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.0.field', 'device');

        $this->assertCount(0, $this->push->sent);
        $this->assertDatabaseCount('device_notifications', 0);
    }

    public function test_it_broadcasts_only_to_the_apps_devices_that_have_a_token(): void
    {
        $this->actAsStaff();
        DeviceSubscription::factory()->create(['app_name' => 'SmartAgent', 'fcm_token' => 'a']);
        DeviceSubscription::factory()->create(['app_name' => 'SmartAgent', 'fcm_token' => 'b']);
        DeviceSubscription::factory()->create(['app_name' => 'SmartAgent', 'fcm_token' => null]);
        DeviceSubscription::factory()->create(['app_name' => 'Fawateer', 'fcm_token' => 'c']);

        $this->postJson('/api/v1/device-notifications/broadcast', [
            'app' => 'SmartAgent',
            'title' => 'تحديث',
            'body' => 'نسخة جديدة متوفرة',
        ])->assertCreated()
            ->assertJsonPath('data.scope', 'broadcast')
            ->assertJsonPath('data.recipients', 2);

        // Two SmartAgent tokens — never the tokenless row, never Fawateer's.
        $this->assertCount(2, $this->push->sent);
        $tokens = array_column($this->push->sent, 'token');
        sort($tokens);
        $this->assertSame(['a', 'b'], $tokens);
    }

    public function test_a_broadcast_can_be_narrowed_to_active_subscribers(): void
    {
        $this->actAsStaff();
        DeviceSubscription::factory()->create([
            'app_name' => 'SmartAgent',
            'fcm_token' => 'active',
            'is_verified' => true,
            'expires_at' => now()->addYear(),
        ]);
        DeviceSubscription::factory()->create([
            'app_name' => 'SmartAgent',
            'fcm_token' => 'expired',
            'is_verified' => true,
            'expires_at' => now()->subDay(),
        ]);

        $this->postJson('/api/v1/device-notifications/broadcast', [
            'app' => 'SmartAgent',
            'active_only' => true,
            'title' => 't',
            'body' => 'b',
        ])->assertCreated()->assertJsonPath('data.recipients', 1);

        $this->assertCount(1, $this->push->sent);
        $this->assertSame('active', $this->push->sent[0]['token']);
    }

    public function test_it_records_and_lists_the_history(): void
    {
        $this->actAsStaff();
        DeviceSubscription::factory()->create(['app_name' => 'SmartAgent', 'fcm_token' => 'a']);

        $this->postJson('/api/v1/device-notifications/broadcast', [
            'app' => 'SmartAgent',
            'title' => 'أول إشعار',
            'body' => 'b',
        ])->assertCreated();

        $this->getJson('/api/v1/device-notifications')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'أول إشعار')
            ->assertJsonPath('data.0.scope', 'broadcast');
    }

    public function test_a_broadcast_to_an_unknown_app_is_rejected(): void
    {
        $this->actAsStaff();

        $this->postJson('/api/v1/device-notifications/broadcast', [
            'app' => 'NotAnApp',
            'title' => 't',
            'body' => 'b',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.0.field', 'app');
    }

    public function test_the_endpoints_require_authentication(): void
    {
        $device = DeviceSubscription::factory()->create(['fcm_token' => 'a']);

        $this->getJson('/api/v1/device-notifications')->assertStatus(401);
        $this->postJson('/api/v1/device-notifications/test', [
            'device' => $device->uuid, 'title' => 't', 'body' => 'b',
        ])->assertStatus(401);
        $this->postJson('/api/v1/device-notifications/broadcast', [
            'app' => 'SmartAgent', 'title' => 't', 'body' => 'b',
        ])->assertStatus(401);

        $this->assertCount(0, $this->push->sent);
    }
}

/**
 * A DevicePushNotifier that records every send instead of hitting FCM.
 */
final class RecordingPushNotifier implements DevicePushNotifier
{
    /** @var list<array{app: string, token: string, title: string, body: string, type: string}> */
    public array $sent = [];

    public function send(string $appName, string $token, string $title, string $body, string $type): void
    {
        $this->sent[] = [
            'app' => $appName,
            'token' => $token,
            'title' => $title,
            'body' => $body,
            'type' => $type,
        ];
    }
}
