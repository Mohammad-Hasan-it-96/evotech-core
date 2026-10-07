<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\DeviceSubscriptions\Application\Services\DeviceCatalogStore;

/**
 * Device-app referrals (ADR 0012).
 *
 * - `device_apps.referral_reward_days`: 0 = off. Only دفتر حسابات opts in (30 days,
 *   owner decision D14), so Fawateer and SmartAgent answer exactly as before.
 * - `device_subscriptions.referral_code` / `referred_by_id`: both server-set, never
 *   mass-assigned.
 * - `device_referral_rewards`: the audit trail, and `referred_id` UNIQUE is what makes
 *   "one reward per referred device" hold under a race.
 *
 * Both foreign keys null on delete: deleting a device from the console must not erase
 * the record that a month was granted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_apps', function (Blueprint $table): void {
            $table->unsignedSmallInteger('referral_reward_days')->default(0)->after('trial_days');
        });

        Schema::table('device_subscriptions', function (Blueprint $table): void {
            $table->string('referral_code', 12)->nullable()->after('comment');
            $table->foreignId('referred_by_id')->nullable()->after('referral_code')
                ->constrained('device_subscriptions')->nullOnDelete();
            $table->unique(['app_name', 'referral_code']);
        });

        Schema::create('device_referral_rewards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referrer_id')->nullable()->constrained('device_subscriptions')->nullOnDelete();
            $table->foreignId('referred_id')->nullable()->unique()->constrained('device_subscriptions')->nullOnDelete();
            $table->string('app_name');
            $table->unsignedSmallInteger('days');
            $table->timestamp('granted_at');
            $table->timestamps();

            $table->index(['referrer_id', 'granted_at']);
        });

        DB::table('device_apps')
            ->where('name', 'daftar_hesabat')
            ->update(['referral_reward_days' => 30, 'updated_at' => now()]);

        app(DeviceCatalogStore::class)->flush();
    }

    public function down(): void
    {
        Schema::dropIfExists('device_referral_rewards');

        Schema::table('device_subscriptions', function (Blueprint $table): void {
            $table->dropUnique(['app_name', 'referral_code']);
            $table->dropConstrainedForeignId('referred_by_id');
            $table->dropColumn('referral_code');
        });

        Schema::table('device_apps', function (Blueprint $table): void {
            $table->dropColumn('referral_reward_days');
        });

        app(DeviceCatalogStore::class)->flush();
    }
};
