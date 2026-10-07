<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\DeviceSubscriptions\Domain\Contracts\DevicePushNotifier;
use Modules\DeviceSubscriptions\Domain\Models\DeviceApp;
use Modules\DeviceSubscriptions\Domain\Models\DeviceReferralReward;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

/**
 * Referrals (ADR 0012): «ادعُ محلاً واحصل على شهر مجاني» for دفتر حسابات.
 *
 * The property that matters most: a reward is paid only on the invited device's
 * first operator activation — never on install or trial — and never twice.
 */
class DeviceReferralTest extends TestCase
{
    use RefreshDatabase;

    private const APP = 'daftar_hesabat';

    private const HASHED_FALLBACK = 'c7a2909940db38ce0aae5f5a07deb0297125ac323da7fb866eca19a77621c7ea';

    private RecordingPushNotifier $push;

    protected function setUp(): void
    {
        parent::setUp();

        $this->push = new RecordingPushNotifier;
        $this->app->instance(DevicePushNotifier::class, $this->push);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return TestResponse<JsonResponse>
     */
    private function register(string $deviceId, array $extra = [], string $app = self::APP): TestResponse
    {
        return $this->postJson('/api/daftar/create_device', [
            'app_name' => $app,
            'device_id' => $deviceId,
            'full_name' => 'محل '.$deviceId,
            'phone' => '0999',
            'fcm_token' => 'token-'.$deviceId,
            ...$extra,
        ])->assertOk();
    }

    private function codeOf(string $deviceId): string
    {
        $code = $this->postJson('/api/daftar/check_device', ['app_name' => self::APP, 'device_id' => $deviceId])
            ->assertOk()
            ->json('referral_code');
        $this->assertIsString($code);

        return $code;
    }

    private function device(string $deviceId): DeviceSubscription
    {
        return DeviceSubscription::query()->where('device_id', $deviceId)->sole();
    }

    private function activate(string $deviceId, string $plan = 'yearly'): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson("/api/v1/device-subscriptions/{$this->device($deviceId)->uuid}/activate", ['plan_id' => $plan])
            ->assertOk();
    }

    public function test_a_device_gets_a_stable_readable_code(): void
    {
        $issued = $this->register('shop-a')
            ->assertJsonPath('referral_rewards', 0)
            ->json('referral_code');

        $code = $this->codeOf('shop-a');
        $this->assertSame($issued, $code);

        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{6}$/', $code);
        $this->assertSame($code, $this->codeOf('shop-a'));
    }

    public function test_apps_without_referrals_answer_exactly_as_before(): void
    {
        $this->register('fawateer-dev', app: 'fawateer')
            ->assertJsonMissingPath('referral_code')
            ->assertJsonMissingPath('referral_rewards');

        $this->postJson('/api/check_device', ['app_name' => 'fawateer', 'device_id' => 'fawateer-dev'])
            ->assertOk()
            ->assertJsonMissingPath('referral_code');

        $this->assertNull($this->device('fawateer-dev')->referral_code);
    }

    public function test_the_shared_fallback_id_never_gets_a_code(): void
    {
        $this->register(self::HASHED_FALLBACK)->assertJsonPath('referral_code', null);
    }

    public function test_a_code_typed_loosely_attributes_the_new_device(): void
    {
        $this->register('shop-a');
        $code = $this->codeOf('shop-a');
        $typed = strtolower(substr($code, 0, 3)).' - '.strtolower(substr($code, 3));

        $this->register('shop-b', ['referral_code' => $typed]);

        $this->assertSame($this->device('shop-a')->id, $this->device('shop-b')->referred_by_id);
    }

    public function test_bad_codes_are_ignored_without_failing_registration(): void
    {
        $this->register('shop-a');
        $own = $this->codeOf('shop-a');

        $this->register('shop-b', ['referral_code' => 'ZZZZZZ']);
        $this->register('shop-a', ['referral_code' => $own]);

        $this->assertNull($this->device('shop-b')->referred_by_id);
        $this->assertNull($this->device('shop-a')->referred_by_id);
    }

    public function test_a_code_from_another_app_is_ignored(): void
    {
        $this->register('shop-a');
        $code = $this->codeOf('shop-a');
        DeviceApp::query()->where('name', 'fawateer')->update(['referral_reward_days' => 30]);

        $this->register('fawateer-dev', ['referral_code' => $code], app: 'fawateer');

        $this->assertNull($this->device('fawateer-dev')->referred_by_id);
    }

    public function test_attribution_is_accepted_during_the_trial_but_set_only_once(): void
    {
        $this->register('shop-a');
        $this->register('shop-c');
        $this->register('shop-b'); // silent registration, trial starts

        $this->register('shop-b', ['referral_code' => $this->codeOf('shop-a')]);
        $this->register('shop-b', ['referral_code' => $this->codeOf('shop-c')]);

        $this->assertSame($this->device('shop-a')->id, $this->device('shop-b')->referred_by_id);
    }

    public function test_a_device_that_already_paid_cannot_be_attributed(): void
    {
        $this->register('shop-a');
        $this->register('shop-b');
        $this->activate('shop-b');

        $this->register('shop-b', ['referral_code' => $this->codeOf('shop-a')]);

        $this->assertNull($this->device('shop-b')->referred_by_id);
    }

    public function test_install_and_trial_earn_nothing(): void
    {
        $this->register('shop-a');
        $before = $this->device('shop-a')->expires_at;

        $this->register('shop-b', ['referral_code' => $this->codeOf('shop-a')]);

        $this->assertEquals($before, $this->device('shop-a')->expires_at);
        $this->assertSame(0, DeviceReferralReward::query()->count());
    }

    public function test_the_first_paid_activation_adds_thirty_days_to_the_referrer(): void
    {
        $this->freezeSecond();
        $this->register('shop-a');
        $trialEnd = $this->device('shop-a')->expires_at;
        $this->assertNotNull($trialEnd);
        $this->register('shop-b', ['referral_code' => $this->codeOf('shop-a')]);

        $this->activate('shop-b');

        $referrer = $this->device('shop-a');
        $this->assertTrue($referrer->is_verified);
        $this->assertEquals($trialEnd->copy()->addDays(30), $referrer->expires_at);

        $this->postJson('/api/daftar/check_device', ['app_name' => self::APP, 'device_id' => 'shop-a'])
            ->assertJsonPath('referral_rewards', 1)
            ->assertJsonPath('is_verified', 1);

        $toReferrer = array_values(array_filter(
            $this->push->sent,
            fn (array $push): bool => $push['token'] === $referrer->fcm_token,
        ));
        $this->assertCount(1, $toReferrer);
        $this->assertSame('new_plan_activated', $toReferrer[0]['type']);
        $this->assertStringContainsString('30', $toReferrer[0]['body']);
    }

    public function test_renewals_never_pay_out_again(): void
    {
        $this->register('shop-a');
        $this->register('shop-b', ['referral_code' => $this->codeOf('shop-a')]);

        $this->activate('shop-b');
        $afterFirst = $this->device('shop-a')->expires_at;
        $this->activate('shop-b');

        $this->assertEquals($afterFirst, $this->device('shop-a')->expires_at);
        $this->assertSame(1, DeviceReferralReward::query()->count());
    }

    public function test_a_lapsed_referrer_gets_thirty_days_from_now(): void
    {
        $this->freezeSecond();
        $this->register('shop-a');
        $this->device('shop-a')->update(['is_verified' => false, 'expires_at' => Carbon::now()->subMonth()]);
        $this->register('shop-b', ['referral_code' => $this->codeOf('shop-a')]);

        $this->activate('shop-b');

        $referrer = $this->device('shop-a');
        $this->assertTrue($referrer->isActive());
        $this->assertEquals(Carbon::now()->addDays(30), $referrer->expires_at);
    }

    public function test_rewards_stop_at_twelve_a_year_but_are_still_recorded(): void
    {
        $this->register('shop-a');
        $referrer = $this->device('shop-a');
        $code = $this->codeOf('shop-a');

        foreach (range(1, 12) as $i) {
            DeviceReferralReward::create([
                'referrer_id' => $referrer->id,
                'referred_id' => null,
                'app_name' => self::APP,
                'days' => 30,
                'granted_at' => Carbon::now()->subDays($i),
            ]);
        }
        $before = $this->device('shop-a')->expires_at;
        $this->register('shop-13', ['referral_code' => $code]);

        $this->activate('shop-13');

        $this->assertEquals($before, $this->device('shop-a')->expires_at);
        $this->assertSame(0, DeviceReferralReward::query()->where('referred_id', $this->device('shop-13')->id)->sole()->days);
    }

    public function test_the_console_sees_who_invited_whom_and_can_tune_the_reward(): void
    {
        $this->register('shop-a');
        $this->register('shop-b', ['referral_code' => $this->codeOf('shop-a')]);
        $this->activate('shop-b');

        // shop-a is the only row with a paid reward; shop-b is the only one invited.
        $this->getJson('/api/v1/device-subscriptions?app_name='.self::APP)
            ->assertOk()
            ->assertJsonFragment(['referred_by' => ['id' => $this->device('shop-a')->uuid, 'full_name' => 'محل shop-a']])
            ->assertJsonFragment(['referral_rewards_count' => 1])
            ->assertJsonMissing(['referral_rewards_count' => 2]);

        $app = DeviceApp::query()->where('name', self::APP)->sole();
        $this->patchJson("/api/v1/device-apps/{$app->uuid}", ['referral_reward_days' => 0])
            ->assertOk()
            ->assertJsonPath('data.referral_reward_days', 0);

        $this->postJson('/api/daftar/check_device', ['app_name' => self::APP, 'device_id' => 'shop-a'])
            ->assertJsonMissingPath('referral_code');
    }
}
