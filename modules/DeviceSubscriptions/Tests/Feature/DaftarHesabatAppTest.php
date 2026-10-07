<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\DeviceSubscriptions\Domain\Models\DeviceApp;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;
use Tests\TestCase;

/**
 * دفتر حسابات (`daftar_hesabat`) as the third device app — S1 of the app's go-live
 * plan (Accounting-Book TASKS.md). The app talks to `/api/daftar/*`, reads
 * `/api/daftar/remote-config`, and sends `app_name: daftar_hesabat` verbatim.
 */
class DaftarHesabatAppTest extends TestCase
{
    use RefreshDatabase;

    private function daftar(): DeviceApp
    {
        return DeviceApp::query()->where('name', 'daftar_hesabat')->sole();
    }

    public function test_the_app_row_carries_the_owners_terms(): void
    {
        $app = $this->daftar();

        $this->assertSame('daftar', $app->slug);
        $this->assertSame('دفتر حسابات', $app->label);
        $this->assertSame(14, $app->trial_days);
        // Its own catalog since 2026_10_07_200000 (the 3-phone plan); see DaftarMultiDevicePlanTest.
        $this->assertFalse($app->uses_shared_plans);
    }

    public function test_first_registration_grants_a_fourteen_day_trial(): void
    {
        $this->freezeTime();

        $this->postJson('/api/daftar/create_device', [
            'app_name' => 'daftar_hesabat',
            'device_id' => 'daftar-dev',
            'full_name' => 'أحمد',
            'phone' => '0999',
        ])
            ->assertOk()
            ->assertJsonPath('is_verified', 1)
            ->assertJsonPath('is_trial', 1)
            ->assertJsonPath('plan', null)
            ->assertJsonPath('expires_at', Carbon::now()->addDays(14)->startOfSecond()->toJSON());
    }

    /** The app's exact request shape for a plan request (Accounting-Book T1.4). */
    public function test_a_plan_request_is_recorded_alongside_the_trial(): void
    {
        $this->postJson('/api/daftar/create_device', [
            'device_id' => 'daftar-dev',
            'full_name' => 'أحمد',
            'phone' => '0999',
            'app_name' => 'daftar_hesabat',
            'requested_plan' => 'yearly',
            'contact_method' => 'whatsapp',
            'status' => 'pending',
        ])->assertOk()->assertJsonPath('is_trial', 1);

        $device = DeviceSubscription::query()->where('device_id', 'daftar-dev')->sole();
        $this->assertSame('yearly', $device->requested_plan);
        $this->assertSame('whatsapp', $device->contact_method);
    }

    public function test_the_slug_serves_its_own_catalog_with_the_same_single_phone_keys(): void
    {
        $this->getJson('/api/daftar/getPlans')
            ->assertOk()
            ->assertJsonPath('plans.0.id', 'half_year')
            ->assertJsonPath('plans.1.id', 'yearly')
            ->assertJsonPath('plans.2.id', 'yearly_multi')
            ->assertJsonPath('currency.code', 'USD');
    }

    /**
     * Pinned exactly: the app's parser is defensive but silent, and these are the
     * values it already compiles in, so the first fetch must change nothing.
     */
    public function test_the_remote_config_matches_what_the_app_compiles_in(): void
    {
        $this->getJson('/api/daftar/remote-config')
            ->assertOk()
            ->assertExactJson([
                'latest_version' => '1.0.0',
                'api' => ['base_url' => 'https://api.evotech-sys.com/api/daftar'],
                'downloads' => [],
                'update_notes' => [],
                'support' => [
                    'email' => 'mohamad.hasan.it.96@gmail.com',
                    'whatsapp' => '963983820430',
                    'telegram' => 'https://t.me/+963983820430',
                ],
            ]);
    }

    /**
     * The client's unreadable-id fallback, hashed with the app's salt: identical on
     * every device that hits it, so it must never receive a trial.
     */
    public function test_the_hashed_fallback_id_gets_no_trial(): void
    {
        $fallback = hash('sha256', 'accounting_book_fallback_accounting_book_app');
        $this->assertTrue(DeviceSubscription::isFallbackId($fallback));

        $this->postJson('/api/daftar/create_device', [
            'app_name' => 'daftar_hesabat',
            'device_id' => $fallback,
            'full_name' => 'x',
            'phone' => '1',
        ])
            ->assertOk()
            ->assertJsonPath('is_verified', 0)
            ->assertJsonPath('is_trial', 0);
    }

    /** Legacy devices are imported, not registered: they must never be retro-trialled. */
    public function test_a_known_device_is_not_given_a_trial(): void
    {
        DeviceSubscription::factory()->create([
            'app_name' => 'daftar_hesabat',
            'device_id' => 'paying-dev',
            'is_verified' => true,
            'plan_id' => 'yearly',
            'expires_at' => Carbon::now()->addMonths(5),
            'trial_expires_at' => null,
        ]);

        $this->postJson('/api/daftar/check_device', [
            'app_name' => 'daftar_hesabat',
            'device_id' => 'paying-dev',
        ])
            ->assertOk()
            ->assertJsonPath('is_verified', 1)
            ->assertJsonPath('is_trial', 0)
            ->assertJsonPath('plan', 'yearly');
    }

    /**
     * Production already ran the config seed before this app existed, so the new
     * migration must insert the row itself — and must not clobber dashboard edits.
     */
    public function test_the_migration_inserts_the_row_on_an_existing_database_and_is_idempotent(): void
    {
        $migration = require base_path(
            'modules/DeviceSubscriptions/Database/Migrations/2026_10_06_100000_add_daftar_hesabat_device_app.php'
        );
        $this->assertInstanceOf(Migration::class, $migration);
        // Reflection because the base Migration class does not declare up().
        $up = new \ReflectionMethod($migration, 'up');

        // Simulate production: the row does not exist yet.
        DB::table('device_apps')->where('name', 'daftar_hesabat')->delete();
        $up->invoke($migration);
        $this->assertSame(14, $this->daftar()->trial_days);
        $this->assertSame('https://api.evotech-sys.com/api/daftar', $this->daftar()->api_base_url);

        // An operator edits it from the dashboard; re-running keeps the edit.
        DeviceApp::query()->where('name', 'daftar_hesabat')->update([
            'latest_version' => '1.2.0',
            'support_whatsapp' => '963900000000',
        ]);
        $up->invoke($migration);
        $this->assertSame('1.2.0', $this->daftar()->latest_version);
        $this->assertSame('963900000000', $this->daftar()->support_whatsapp);
        $this->assertSame(1, DeviceApp::query()->where('name', 'daftar_hesabat')->count());
    }
}
