<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The remote-config an app fetches at startup: which version is current, where to
 * download it, what changed, and how to reach support.
 *
 * This lived in a hand-edited static file (evotech-web `public/config/fawateer.json`).
 * Moving it here makes it editable from the dashboard and lets the Download Center
 * populate the links, which is what §9 of docs/api/fawateer-device-contract.md names
 * as the intended end state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_apps', function (Blueprint $table): void {
            /*
             * Compared component-by-component as integers by both shipped apps, NOT
             * as semver. A suffix like "1.2.0-beta" parses that component as 0, which
             * silently makes the update invisible rather than erroring — so the
             * request layer constrains this to digits and dots.
             */
            $table->string('latest_version', 20)->nullable()->after('product_id');

            /*
             * Overrides the derived `/api/{slug}` base URL. Nullable, and null is the
             * normal state: an operator should not have to type this correctly for
             * the app to work. It exists because repointing an app at a different
             * backend with no store release is the whole reason the remote config
             * exists.
             */
            $table->string('api_base_url', 255)->nullable()->after('latest_version');

            /*
             * ABI → APK URL, e.g. {"arm64-v8a": "https://…"}. Keys are matched
             * exactly against the device's reported ABI, so they are constrained to
             * the known set at the request layer; a typo'd key is not an error, it
             * is an update the device can never find.
             */
            $table->json('downloads')->nullable()->after('api_base_url');

            // Array of strings. A bare string is dropped by Fawateer's parser.
            $table->json('update_notes')->nullable()->after('downloads');

            $table->string('support_email', 150)->nullable()->after('update_notes');
            $table->string('support_whatsapp', 30)->nullable()->after('support_email');
            $table->string('support_telegram', 150)->nullable()->after('support_whatsapp');
        });
    }

    public function down(): void
    {
        Schema::table('device_apps', function (Blueprint $table): void {
            $table->dropColumn([
                'latest_version',
                'api_base_url',
                'downloads',
                'update_notes',
                'support_email',
                'support_whatsapp',
                'support_telegram',
            ]);
        });
    }
};
