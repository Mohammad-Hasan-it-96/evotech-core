<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\DeviceSubscriptions\Application\Services\DeviceCatalogStore;
use Ramsey\Uuid\Uuid;

/**
 * Adds دفتر حسابات (`daftar_hesabat`) as the third device app, with its remote config.
 *
 * Production already ran the config seed (2026_07_19_100200) before this app had a
 * config entry, so that migration will never insert it. This one does — and it runs
 * from `migrate --force` like the other seeds, because the deploy never runs
 * `db:seed`.
 *
 * Converges on both paths:
 *  - production: no row yet → insert it;
 *  - a fresh database (tests, new environments): the config seed has already
 *    inserted it from the new config entry → only fill what that seed leaves out
 *    (the product link and the remote config).
 *
 * `name` is the literal `app_name` the shipped builds send, including the one
 * paying device on the legacy backend (docs/GO-LIVE-FAWATEER.md §3). `name` and
 * `slug` are immutable once written (the dashboard does not offer them).
 *
 * The remote config is seeded only on a row nobody has edited (`latest_version`
 * null), like the Fawateer and SmartAgent seeds, so re-running cannot clobber
 * dashboard edits. `api_base_url` is stored explicitly rather than derived from
 * `app.url`: a wrong-but-non-empty base URL is worse than none, because the app
 * accepts it and talks to the wrong host. Support contacts are the ones the app
 * already compiles in, so the first fetch changes nothing a user sees.
 */
return new class extends Migration
{
    private const NAME = 'daftar_hesabat';

    public function up(): void
    {
        $now = now();
        $productId = DB::table('products')->where('slug', 'ledger')->value('id');

        $app = DB::table('device_apps')->where('name', self::NAME)->first();

        if ($app === null) {
            DB::table('device_apps')->insert([
                'uuid' => Uuid::uuid7()->toString(),
                'name' => self::NAME,
                'slug' => 'daftar',
                'label' => 'دفتر حسابات',
                'trial_days' => 14,
                'uses_shared_plans' => true,
                'product_id' => $productId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $app = DB::table('device_apps')->where('name', self::NAME)->sole();
        } elseif ($app->product_id === null && $productId !== null) {
            DB::table('device_apps')->where('id', $app->id)->update([
                'product_id' => $productId,
                'updated_at' => $now,
            ]);
        }

        if ($app->latest_version === null) {
            DB::table('device_apps')->where('id', $app->id)->update([
                // The first release that reads this config (pubspec 1.0.0). Equal to
                // the installed version → no update prompt until a newer one ships.
                'latest_version' => '1.0.0',
                'api_base_url' => 'https://api.evotech-sys.com/api/daftar',
                'downloads' => null,
                'update_notes' => null,
                'support_email' => 'mohamad.hasan.it.96@gmail.com',
                'support_whatsapp' => '963983820430',
                'support_telegram' => 'https://t.me/+963983820430',
                'updated_at' => $now,
            ]);
        }

        // The catalog is cached for 5 minutes; a device must not be told "unknown
        // app" (no trial) for that long after the deploy.
        app(DeviceCatalogStore::class)->flush();
    }

    public function down(): void
    {
        // Devices are keyed by the app_name string, not a foreign key, so removing
        // the row orphans nothing — those devices fall back to the defaults (no
        // trial, raw name as label), which is exactly the state before this ran.
        DB::table('device_apps')->where('name', self::NAME)->delete();
        app(DeviceCatalogStore::class)->flush();
    }
};
