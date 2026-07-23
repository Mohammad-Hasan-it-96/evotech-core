<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;
use Tests\TestCase;

/**
 * The trial-expiry invariant: a trial row must carry its expiry, because
 * `expires_at = NULL` means "lifetime" and a trial that loses its expiry is
 * verified forever. The 2026-07-22 legacy import produced exactly that state on
 * a device that had self-registered here (and been trialled) before its legacy
 * twin was upserted over it. These tests pin every layer of the fix: the grant,
 * the profile-update whitelist, the model guards, the repair migration, and the
 * import command's collision rule.
 */
class TrialExpiryInvariantTest extends TestCase
{
    use RefreshDatabase;

    /**
     * On MySQL the corrupted state is uninsertable — the CHECK constraint added
     * by the repair migration rejects it at the schema level, which is itself
     * the fix working (asserted by [test_mysql_rejects_the_corrupted_state]).
     * The heal-path tests therefore only run where the state is representable.
     */
    private function requiresRepresentableCorruption(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL rejects this state outright via chk_trial_rows_keep_expiry.');
        }
    }

    /** Insert the corrupted state raw, bypassing the Eloquent guards — as a hand-run SQL import would. */
    private function insertAfflictedRow(): void
    {
        DB::table('device_subscriptions')->insert([
            'uuid' => (string) Str::uuid(),
            'app_name' => 'Fawateer',
            'device_id' => 'afflicted-1',
            'full_name' => 'صاحب الجهاز',
            'phone' => '099',
            'is_verified' => true,
            'expires_at' => null,
            'trial_expires_at' => Carbon::now()->addDays(24),
            'plan_id' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    public function test_granting_a_trial_persists_both_dates(): void
    {
        $this->freezeTime();

        $this->postJson('/api/fawateer/create_device', [
            'app_name' => 'Fawateer',
            'device_id' => 'fresh-1',
            'full_name' => 'أبو أحمد',
            'phone' => '099',
        ])->assertOk();

        $device = DeviceSubscription::query()->where('device_id', 'fresh-1')->sole();
        $this->assertNotNull($device->expires_at);
        $this->assertNotNull($device->trial_expires_at);
        $this->assertTrue($device->expires_at->equalTo($device->trial_expires_at));
    }

    public function test_a_profile_update_never_changes_the_expiry(): void
    {
        $this->postJson('/api/fawateer/create_device', [
            'app_name' => 'Fawateer',
            'device_id' => 'fresh-2',
            'full_name' => 'أبو أحمد',
            'phone' => '099',
        ])->assertOk();

        $before = DeviceSubscription::query()->where('device_id', 'fresh-2')->sole()->expires_at;
        $this->assertNotNull($before);

        // Includes an injection attempt: subscription fields in the payload must
        // be ignored by the whitelist, not applied.
        $this->postJson('/api/fawateer/update_my_data', [
            'app_name' => 'Fawateer',
            'device_id' => 'fresh-2',
            'full_name' => 'اسم جديد',
            'phone' => '0999',
            'fcm_token' => 'rotated',
            'expires_at' => null,
            'is_verified' => 0,
            'plan_id' => 'yearly',
        ])->assertOk();

        $device = DeviceSubscription::query()->where('device_id', 'fresh-2')->sole();
        $this->assertSame('اسم جديد', $device->full_name);
        $this->assertSame('rotated', $device->fcm_token);
        $this->assertNotNull($device->expires_at);
        $this->assertTrue($device->expires_at->equalTo($before));
        $this->assertTrue($device->is_verified);
        $this->assertNull($device->plan_id);
    }

    public function test_mysql_rejects_the_corrupted_state(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The CHECK constraint only exists on MySQL.');
        }

        $this->expectException(QueryException::class);
        $this->insertAfflictedRow();
    }

    public function test_check_device_answers_a_corrupted_trial_row_with_its_trial_expiry(): void
    {
        $this->requiresRepresentableCorruption();
        $this->insertAfflictedRow();

        // The retrieved-heal makes the corruption invisible at the API surface
        // even before the repair migration has touched the row.
        $this->postJson('/api/fawateer/check_device', [
            'app_name' => 'Fawateer',
            'device_id' => 'afflicted-1',
        ])->assertOk()
            ->assertJsonPath('is_verified', 1)
            ->assertJsonPath('is_trial', 1)
            ->assertJsonPath('expires_at', fn ($value) => $value !== null);
    }

    public function test_a_corrupted_trial_row_locks_when_the_trial_lapses(): void
    {
        $this->requiresRepresentableCorruption();
        $this->insertAfflictedRow();

        // The whole point of the bug: without the heal this device stayed
        // verified forever.
        $this->travel(25)->days();

        $this->postJson('/api/fawateer/check_device', [
            'app_name' => 'Fawateer',
            'device_id' => 'afflicted-1',
        ])->assertOk()
            ->assertJsonPath('is_verified', 0)
            ->assertJsonPath('is_trial', 0);
    }

    public function test_an_eloquent_write_cannot_clear_a_trial_expiry(): void
    {
        $this->postJson('/api/fawateer/create_device', [
            'app_name' => 'Fawateer',
            'device_id' => 'fresh-3',
            'full_name' => 'أبو أحمد',
            'phone' => '099',
        ])->assertOk();

        $device = DeviceSubscription::query()->where('device_id', 'fresh-3')->sole();
        $device->update(['expires_at' => null]);

        $this->assertNotNull($device->refresh()->expires_at);
    }

    public function test_the_repair_migration_fixes_an_afflicted_row_and_spares_lifetime_plans(): void
    {
        $this->requiresRepresentableCorruption();
        $this->insertAfflictedRow();

        // A genuine lifetime subscription: NULL expiry WITH a plan — must survive.
        DB::table('device_subscriptions')->insert([
            'uuid' => (string) Str::uuid(),
            'app_name' => 'SmartAgent',
            'device_id' => 'lifetime-1',
            'is_verified' => true,
            'expires_at' => null,
            'trial_expires_at' => null,
            'plan_id' => 'yearly',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $migration = require base_path(
            'modules/DeviceSubscriptions/Database/Migrations/2026_07_23_150000_repair_trial_rows_missing_expiry.php'
        );
        $this->assertInstanceOf(Migration::class, $migration);
        // Reflection because the base Migration class does not declare up().
        (new \ReflectionMethod($migration, 'up'))->invoke($migration);

        $this->assertNotNull(
            DeviceSubscription::query()->where('device_id', 'afflicted-1')->sole()->getRawOriginal('expires_at')
        );
        $this->assertNull(
            DeviceSubscription::query()->where('device_id', 'lifetime-1')->sole()->getRawOriginal('expires_at')
        );
    }

    /** The actual production incident, replayed against the guarded import. */
    public function test_the_legacy_import_no_longer_erases_a_trial(): void
    {
        $this->freezeTime();

        // The device registers fresh on the new server and is granted a trial…
        $this->postJson('/api/fawateer/create_device', [
            'app_name' => 'Fawateer',
            'device_id' => 'both-worlds-1',
            'full_name' => 'أبو أحمد',
            'phone' => '099',
        ])->assertOk();

        // …while its legacy twin sits in app_harfoshs: hand-verified, no plan,
        // no expiry. A second in-memory SQLite connection stands in for the
        // legacy database.
        config(['database.connections.legacy_test' => ['driver' => 'sqlite', 'database' => ':memory:']]);
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
        DB::connection('legacy_test')->table('app_harfoshs')->insert([
            [
                'app_name' => 'Fawateer',
                'device_id' => 'both-worlds-1',
                'full_name' => 'الاسم القديم',
                'phone' => '0911',
                'is_verified' => true,
                'expires_at' => null,
                'plan_id' => null,
                'created_at' => Carbon::now()->subYear(),
                'updated_at' => Carbon::now()->subYear(),
            ],
            [
                // A paying legacy customer must still import in full.
                'app_name' => 'SmartAgent',
                'device_id' => 'paying-1',
                'full_name' => 'زبون قديم',
                'phone' => '0922',
                'is_verified' => true,
                'expires_at' => Carbon::now()->addMonths(9),
                'plan_id' => 'yearly',
                'created_at' => Carbon::now()->subMonths(3),
                'updated_at' => Carbon::now()->subMonths(3),
            ],
        ]);

        config(['device-subscriptions.legacy.connection' => 'legacy_test']);
        $this->assertSame(0, Artisan::call('device-subscriptions:import-legacy'));

        // The trial survived the collision; the profile still refreshed.
        $trialled = DeviceSubscription::query()->where('device_id', 'both-worlds-1')->sole();
        $this->assertNotNull($trialled->expires_at);
        $this->assertNotNull($trialled->trial_expires_at);
        $this->assertTrue($trialled->isOnTrial());
        $this->assertSame('الاسم القديم', $trialled->full_name);

        // The paying customer imported with their plan and expiry intact.
        $paying = DeviceSubscription::query()->where('device_id', 'paying-1')->sole();
        $this->assertSame('yearly', $paying->plan_id);
        $this->assertNotNull($paying->expires_at);
    }
}
