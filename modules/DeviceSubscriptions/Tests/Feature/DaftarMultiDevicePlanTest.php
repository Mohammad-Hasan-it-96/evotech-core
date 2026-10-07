<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\DeviceSubscriptions\Application\Services\DeviceCatalogStore;
use Modules\DeviceSubscriptions\Domain\Models\DeviceApp;
use Modules\DeviceSubscriptions\Domain\Models\DevicePlan;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

/**
 * Migration 2026_10_07_200000: دفتر حسابات gets its own catalog with a 3-phone plan
 * (`yearly_multi`, 12 months, $35, allowance 3), keeping the single-phone keys so no
 * existing device changes meaning, and leaving Fawateer on the shared list.
 */
class DaftarMultiDevicePlanTest extends TestCase
{
    use RefreshDatabase;

    private const APP = 'daftar_hesabat';

    private function runMigration(): void
    {
        $migration = require base_path(
            'modules/DeviceSubscriptions/Database/Migrations/2026_10_07_200000_give_daftar_own_catalog_with_multi_device_plan.php'
        );
        $this->assertInstanceOf(Migration::class, $migration);
        (new \ReflectionMethod($migration, 'up'))->invoke($migration);
    }

    private function daftar(): DeviceApp
    {
        return DeviceApp::query()->where('name', self::APP)->sole();
    }

    public function test_daftar_sells_three_plans_and_the_yearly_single_phone_stays_recommended(): void
    {
        $this->getJson('/api/daftar/getPlans')
            ->assertOk()
            ->assertJsonCount(3, 'plans')
            ->assertJsonPath('plans.0.id', 'half_year')
            ->assertJsonPath('plans.1.id', 'yearly')
            ->assertJsonPath('plans.1.recommended', true)
            ->assertJsonPath('plans.2.id', 'yearly_multi')
            ->assertJsonPath('plans.2.price', 35)
            ->assertJsonPath('plans.2.duration_months', 12)
            ->assertJsonPath('plans.2.recommended', false);
    }

    public function test_the_allowance_is_three_only_on_the_multi_device_plan_and_fawateer_is_untouched(): void
    {
        $this->assertSame(3, DevicePlan::allowanceFor('yearly_multi', self::APP));
        $this->assertSame(1, DevicePlan::allowanceFor('yearly', self::APP));

        $this->assertTrue(DeviceApp::query()->where('name', 'Fawateer')->sole()->uses_shared_plans);
        $this->assertNull(DevicePlan::allowanceFor('yearly_multi', 'Fawateer'));
        $this->assertSame(
            ['half_year', 'yearly'],
            array_column((array) $this->getJson('/api/fawateer/getPlans')->json('plans'), 'id'),
        );
    }

    /** @return TestResponse<JsonResponse> */
    private function activateAndOnboard(string $deviceId, string $plan): TestResponse
    {
        $this->postJson('/api/daftar/create_device', [
            'app_name' => self::APP, 'device_id' => $deviceId, 'full_name' => 'محل', 'phone' => '0999',
        ])->assertOk();
        $device = DeviceSubscription::query()->where('device_id', $deviceId)->sole();

        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->postJson("/api/v1/device-subscriptions/{$device->uuid}/activate", ['plan_id' => $plan])->assertOk();

        return $this->postJson('/api/v1/sync/business', ['app_name' => self::APP, 'device_id' => $deviceId])
            ->assertCreated();
    }

    public function test_a_shop_on_the_multi_device_plan_gets_three_seats_for_a_year(): void
    {
        $this->freezeSecond();

        $this->activateAndOnboard('multi-shop', 'yearly_multi')->assertJsonPath('data.device_allowance', 3);

        $device = DeviceSubscription::query()->where('device_id', 'multi-shop')->sole();
        $this->assertEquals(Carbon::now()->addMonths(12), $device->expires_at);
    }

    public function test_a_single_phone_plan_still_gets_one_seat(): void
    {
        $this->activateAndOnboard('single-shop', 'yearly')->assertJsonPath('data.device_allowance', 1);
    }

    public function test_it_copies_the_live_shared_prices_and_is_idempotent(): void
    {
        // Production state before the migration: Daftar on the shared list, whose
        // yearly price an operator has edited.
        $app = $this->daftar();
        DevicePlan::query()->where('device_app_id', $app->id)->delete();
        $app->update(['uses_shared_plans' => true]);
        DevicePlan::query()->whereNull('device_app_id')->where('plan_key', 'yearly')->update(['price' => 25]);
        app(DeviceCatalogStore::class)->flush();

        $this->runMigration();
        $this->runMigration();

        $own = DevicePlan::query()->where('device_app_id', $app->id)->orderBy('sort_order')->get();
        $this->assertSame(['half_year', 'yearly', 'yearly_multi'], $own->pluck('plan_key')->all());
        $this->assertSame(25.0, (float) $own->firstWhere('plan_key', 'yearly')?->price);
        $this->assertFalse($this->daftar()->uses_shared_plans);
    }

    public function test_an_operator_edit_to_the_multi_device_plan_survives_a_rerun(): void
    {
        DevicePlan::query()->where('plan_key', 'yearly_multi')->update(['price' => 30]);

        $this->runMigration();

        $this->assertSame(1, DevicePlan::query()->where('plan_key', 'yearly_multi')->count());
        $this->assertSame(30.0, (float) DevicePlan::query()->where('plan_key', 'yearly_multi')->sole()->price);
    }
}
