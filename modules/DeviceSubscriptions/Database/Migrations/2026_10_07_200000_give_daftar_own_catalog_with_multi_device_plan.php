<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\DeviceSubscriptions\Application\Services\DeviceCatalogStore;
use Ramsey\Uuid\Uuid;

/**
 * دفتر حسابات gets its own plan catalog with a 3-phone plan (Accounting-Book T3.1,
 * owner decision D8, 2026-10-07: 12 months, $35, allowance 3; the yearly
 * single-phone plan stays the recommended one).
 *
 * Why a catalog of its own rather than raising the shared plans' allowance: the
 * shared list is also Fawateer's, and multi-device there is a separate decision.
 *
 * Steps, all idempotent:
 * 1. While Daftar still reads the shared list, copy each shared plan as Daftar's own
 *    row **with its current values** (an operator's price edit survives). Same
 *    `plan_key`s, so every device already on `half_year`/`yearly` resolves exactly
 *    as before: the term, title and allowance come from the identical row.
 * 2. Add `yearly_multi` if missing. Its `plan_key` is a contract from here on:
 *    the app sends it back as `requested_plan` and activation stores it.
 * 3. Switch Daftar to its own catalog, and drop the cached catalog.
 *
 * A catalog an operator already made Daftar's own is left alone apart from step 2.
 */
return new class extends Migration
{
    private const APP = 'daftar_hesabat';

    private const MULTI = 'yearly_multi';

    public function up(): void
    {
        $app = DB::table('device_apps')->where('name', self::APP)->first();

        if ($app === null) {
            return;
        }

        $now = now();

        if ((bool) $app->uses_shared_plans) {
            $shared = DB::table('device_plans')->whereNull('device_app_id')->orderBy('sort_order')->orderBy('id')->get();

            foreach ($shared as $plan) {
                $exists = DB::table('device_plans')
                    ->where('device_app_id', $app->id)
                    ->where('plan_key', $plan->plan_key)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('device_plans')->insert([
                    'uuid' => Uuid::uuid7()->toString(),
                    'device_app_id' => $app->id,
                    'plan_key' => $plan->plan_key,
                    'title' => $plan->title,
                    'description' => $plan->description,
                    'duration_months' => $plan->duration_months,
                    'device_allowance' => $plan->device_allowance,
                    'price' => $plan->price,
                    'price_after_discount' => $plan->price_after_discount,
                    'enabled' => $plan->enabled,
                    'recommended' => $plan->recommended,
                    'sort_order' => $plan->sort_order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $hasMulti = DB::table('device_plans')
            ->where('device_app_id', $app->id)
            ->where('plan_key', self::MULTI)
            ->exists();

        if (! $hasMulti) {
            $nextSort = (int) DB::table('device_plans')->where('device_app_id', $app->id)->max('sort_order') + 1;

            DB::table('device_plans')->insert([
                'uuid' => Uuid::uuid7()->toString(),
                'device_app_id' => $app->id,
                'plan_key' => self::MULTI,
                'title' => 'الخطة السنوية — 3 أجهزة',
                'description' => 'دفتر واحد على 3 هواتف في المحل، يتزامن تلقائياً',
                'duration_months' => 12,
                'device_allowance' => 3,
                'price' => 35,
                'price_after_discount' => null,
                'enabled' => true,
                'recommended' => false,
                'sort_order' => $nextSort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('device_apps')->where('id', $app->id)->update([
            'uses_shared_plans' => false,
            'updated_at' => $now,
        ]);

        app(DeviceCatalogStore::class)->flush();
    }

    public function down(): void
    {
        // Back to the shared list. Daftar's own rows are kept (harmless while unused,
        // and a device may hold `yearly_multi`); an operator can delete them.
        DB::table('device_apps')->where('name', self::APP)->update(['uses_shared_plans' => true]);
        app(DeviceCatalogStore::class)->flush();
    }
};
