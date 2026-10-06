<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;
use Tests\TestCase;

/**
 * The legacy import as a per-app cutover tool: scoped by --app, with a drift
 * report (new / changed / unchanged) a dry run can show before anything is
 * written. Built for دفتر حسابات's S2 — its two legacy devices were imported with
 * everything else on 2026-07-22 and may have drifted since.
 */
class ImportLegacyDevicesCommandTest extends TestCase
{
    use RefreshDatabase;

    private const DAFTAR_FALLBACK = 'c7a2909940db38ce0aae5f5a07deb0297125ac323da7fb866eca19a77621c7ea';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();

        // A second in-memory SQLite connection stands in for the legacy database.
        config(['database.connections.legacy_test' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        config(['device-subscriptions.legacy.connection' => 'legacy_test']);
        Schema::connection('legacy_test')->create('app_harfoshs', function (Blueprint $table): void {
            $table->id();
            $table->string('app_name')->nullable();
            $table->string('device_id')->nullable();
            $table->string('full_name')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->string('plan_id')->nullable();
            $table->text('fcm_token')->nullable();
            $table->integer('stars')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    /** @param  array<string, mixed>  $row */
    private function legacy(array $row): void
    {
        DB::connection('legacy_test')->table('app_harfoshs')->insert($row + [
            'full_name' => 'زبون',
            'phone' => '0999',
            'is_verified' => false,
            'expires_at' => null,
            'plan_id' => null,
            'created_at' => Carbon::now()->subYear(),
            'updated_at' => Carbon::now()->subYear(),
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function existing(array $attributes): void
    {
        DeviceSubscription::factory()->create($attributes + [
            'is_verified' => false,
            'plan_id' => null,
            'expires_at' => null,
            'trial_expires_at' => null,
        ]);
    }

    /** @param  array<string, mixed>  $options */
    private function import(array $options = []): string
    {
        $this->assertSame(0, Artisan::call('device-subscriptions:import-legacy', $options));

        return Artisan::output();
    }

    public function test_app_scopes_the_import_to_one_product(): void
    {
        $this->legacy(['app_name' => 'daftar_hesabat', 'device_id' => 'd-1', 'is_verified' => true,
            'plan_id' => 'yearly', 'expires_at' => Carbon::now()->addMonths(8)]);
        $this->legacy(['app_name' => 'SmartAgent', 'device_id' => 's-1', 'is_verified' => true,
            'plan_id' => 'yearly', 'expires_at' => Carbon::now()->addMonths(3)]);

        $out = $this->import(['--app' => ['daftar_hesabat']]);

        $this->assertStringContainsString('Imported 1 device(s)', $out);
        $this->assertTrue(DeviceSubscription::query()->where('device_id', 'd-1')->exists());
        $this->assertFalse(DeviceSubscription::query()->where('device_id', 's-1')->exists());
    }

    public function test_a_dry_run_reports_drift_and_writes_nothing(): void
    {
        $renewedUntil = Carbon::now()->addMonths(11);

        // Imported in July, renewed on the legacy server since → changed.
        $this->existing(['app_name' => 'daftar_hesabat', 'device_id' => 'paying', 'is_verified' => true,
            'plan_id' => 'yearly', 'expires_at' => Carbon::now()->addMonth()]);
        $this->legacy(['app_name' => 'daftar_hesabat', 'device_id' => 'paying', 'is_verified' => true,
            'plan_id' => 'yearly', 'expires_at' => $renewedUntil]);

        // Identical on both sides → unchanged.
        $this->existing(['app_name' => 'daftar_hesabat', 'device_id' => 'free']);
        $this->legacy(['app_name' => 'daftar_hesabat', 'device_id' => 'free']);

        // Registered on the legacy server after the import → new.
        $this->legacy(['app_name' => 'daftar_hesabat', 'device_id' => 'late']);

        $out = $this->import(['--app' => ['daftar_hesabat'], '--dry-run' => true]);

        $this->assertStringContainsString('Would import 3 device(s)', $out);
        $this->assertMatchesRegularExpression('/daftar_hesabat\s*\|\s*3\s*\|\s*1\s*\|\s*1\s*\|\s*1\s*\|\s*1\s*\|\s*0/', $out);
        // Nothing written: the drifted expiry is still the old one, the new device absent.
        $this->assertTrue(DeviceSubscription::query()->where('device_id', 'paying')->sole()
            ->expires_at?->lt($renewedUntil));
        $this->assertFalse(DeviceSubscription::query()->where('device_id', 'late')->exists());
        // No PII in the report.
        $this->assertStringNotContainsString('زبون', $out);
        $this->assertStringNotContainsString('0999', $out);
    }

    public function test_the_real_run_applies_the_drift(): void
    {
        $renewedUntil = Carbon::now()->addMonths(11)->startOfSecond();
        $this->existing(['app_name' => 'daftar_hesabat', 'device_id' => 'paying', 'is_verified' => true,
            'plan_id' => 'half_year', 'expires_at' => Carbon::now()->addMonth()]);
        $this->legacy(['app_name' => 'daftar_hesabat', 'device_id' => 'paying', 'is_verified' => true,
            'plan_id' => 'yearly', 'expires_at' => $renewedUntil]);

        $this->import(['--app' => ['daftar_hesabat']]);

        $device = DeviceSubscription::query()->where('device_id', 'paying')->sole();
        $this->assertSame('yearly', $device->plan_id);
        $this->assertSame($renewedUntil->getTimestamp(), $device->expires_at?->getTimestamp());

        // Re-running is a no-op: everything now reads unchanged.
        $out = $this->import(['--app' => ['daftar_hesabat'], '--dry-run' => true]);
        $this->assertMatchesRegularExpression('/daftar_hesabat\s*\|\s*1\s*\|\s*0\s*\|\s*0\s*\|\s*1\s*\|/', $out);
    }

    public function test_a_fallback_id_holding_a_plan_is_flagged(): void
    {
        $this->legacy(['app_name' => 'daftar_hesabat', 'device_id' => self::DAFTAR_FALLBACK,
            'is_verified' => true, 'plan_id' => 'yearly', 'expires_at' => Carbon::now()->addMonths(6)]);

        $out = $this->import(['--app' => ['daftar_hesabat'], '--dry-run' => true]);

        $this->assertStringContainsString('shared fallback device id holds plan', $out);
        $this->assertMatchesRegularExpression('/daftar_hesabat(\s*\|\s*\d+){5}\s*\|\s*1\s*\|/', $out);
    }

    public function test_without_app_every_product_is_imported_as_before(): void
    {
        $this->legacy(['app_name' => 'daftar_hesabat', 'device_id' => 'd-1']);
        $this->legacy(['app_name' => 'SmartAgent', 'device_id' => 's-1']);

        $this->assertStringContainsString('Imported 2 device(s)', $this->import());
        $this->assertSame(2, DeviceSubscription::query()->count());
    }
}
