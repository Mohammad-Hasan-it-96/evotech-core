<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\DeviceSubscriptions\Domain\Contracts\DevicePushNotifier;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;
use Modules\Users\Domain\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The subscription-lifecycle pushes, ported from the legacy SendPlanNotifications
 * command: reminders at the 7 / 3 / 1-day marks, the "last day" notice, a bounded
 * post-expiry window, and the activation push. The copy is the wording SmartAgent
 * users have received for years — these tests pin it, per stage, with the per-app
 * label substituted for the hardcoded product name.
 */
class ExpiryReminderSweepTest extends TestCase
{
    use RefreshDatabase;

    private RecordingPushNotifier $push;

    protected function setUp(): void
    {
        parent::setUp();

        $this->push = new RecordingPushNotifier;
        $this->app->instance(DevicePushNotifier::class, $this->push);

        $this->freezeTime();
    }

    private function device(Carbon $expiresAt, string $appName = 'SmartAgent'): DeviceSubscription
    {
        return DeviceSubscription::factory()->create([
            'app_name' => $appName,
            'full_name' => 'أبو أحمد',
            'fcm_token' => 'tok-'.fake()->unique()->uuid(),
            'is_verified' => true,
            'expires_at' => $expiresAt,
        ]);
    }

    private function sweep(): int
    {
        $this->assertSame(0, Artisan::call('device-subscriptions:sweep-expiry'));

        return count($this->push->sent);
    }

    /** @return array<string, array{int, string, string}> */
    public static function reminderStages(): array
    {
        return [
            '7 days out' => [7, 'still_7_days', '📅 اشتراكك ينتهي بعد 7 أيام'],
            '3 days out' => [3, 'still_3_days', '⏳ تبقّى 3 أيام على انتهاء اشتراكك'],
            '1 day out' => [1, 'still_1_day', '⚠️ آخر يوم في اشتراكك!'],
        ];
    }

    #[DataProvider('reminderStages')]
    public function test_it_reminds_at_each_mark_with_the_stage_copy(int $days, string $type, string $title): void
    {
        $this->device(Carbon::now()->addDays($days)->addHours(2));

        $this->assertSame(1, $this->sweep());
        $this->assertSame($type, $this->push->sent[0]['type']);
        $this->assertSame($title, $this->push->sent[0]['title']);
        // Personalized, per-stage copy — not one generic renewal line.
        $this->assertStringContainsString('أبو أحمد', $this->push->sent[0]['body']);
        $this->assertStringContainsString('المندوب الذكي', $this->push->sent[0]['body']);
    }

    /** The "last day": expiring later today counts as day 0, as in legacy. */
    public function test_the_last_day_gets_the_expiry_notice(): void
    {
        $this->device(Carbon::now()->addHours(5));

        $this->assertSame(1, $this->sweep());
        $this->assertSame('plan_deactivated', $this->push->sent[0]['type']);
        $this->assertSame('🔴 انتهت صلاحية اشتراكك', $this->push->sent[0]['title']);
    }

    public function test_a_recently_expired_device_is_still_nudged(): void
    {
        $this->device(Carbon::now()->subDays(3));

        $this->assertSame(1, $this->sweep());
        $this->assertSame('plan_deactivated', $this->push->sent[0]['type']);
    }

    /**
     * The legacy *endpoint* variant nagged every expired device daily, forever;
     * the sweep keeps the legacy command's bounded window instead.
     */
    public function test_the_expired_nudge_stops_after_the_window(): void
    {
        $this->device(Carbon::now()->subDays(5));

        $this->assertSame(0, $this->sweep());
    }

    public function test_days_between_marks_stay_silent(): void
    {
        $this->device(Carbon::now()->addDays(5));

        $this->assertSame(0, $this->sweep());
    }

    public function test_devices_without_a_token_or_expiry_are_skipped(): void
    {
        DeviceSubscription::factory()->create([
            'fcm_token' => null,
            'expires_at' => Carbon::now()->addDays(7)->addHours(2),
        ]);
        DeviceSubscription::factory()->create([
            'fcm_token' => 'tok-lifetime',
            'expires_at' => null,
        ]);

        $this->assertSame(0, $this->sweep());
    }

    /** A Fawateer user must be asked to renew فواتير, not المندوب الذكي. */
    public function test_the_reminder_names_the_devices_own_app(): void
    {
        $this->device(Carbon::now()->addDays(7)->addHours(2), 'Fawateer');

        $this->assertSame(1, $this->sweep());
        $this->assertStringContainsString('فواتير', $this->push->sent[0]['body']);
        $this->assertStringNotContainsString('المندوب الذكي', $this->push->sent[0]['body']);
    }

    /** Legacy parity: the activation push names the plan the customer bought. */
    public function test_activation_pushes_the_plan_title_and_expiry(): void
    {
        $device = DeviceSubscription::factory()->create([
            'app_name' => 'SmartAgent',
            'device_id' => 'dev-act-1',
            'full_name' => 'أبو أحمد',
            'fcm_token' => 'tok-act',
            'is_verified' => false,
        ]);

        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->postJson('/api/activateDevice', [
            'device_id' => 'dev-act-1',
            'plan_id' => 'yearly',
        ])->assertOk();

        $this->assertCount(1, $this->push->sent);
        $this->assertSame('new_plan_activated', $this->push->sent[0]['type']);
        $this->assertSame('🎉 تم تفعيل اشتراكك بنجاح!', $this->push->sent[0]['title']);
        $this->assertStringContainsString('الخطة السنوية', $this->push->sent[0]['body']);
        $this->assertStringContainsString(
            Carbon::now()->addMonths(12)->format('Y/m/d'),
            $this->push->sent[0]['body'],
        );

        $this->assertTrue($device->refresh()->isActive());
    }
}
