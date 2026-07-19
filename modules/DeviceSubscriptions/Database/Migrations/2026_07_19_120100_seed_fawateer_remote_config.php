<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Carries the hand-edited `public/config/fawateer.json` into the database.
 *
 * Values are copied from the live file as served at the time of writing, so the
 * generated config is byte-equivalent on the first request and no user sees a
 * change. Without this the endpoint would go live emptier than the file it
 * replaces — and the app's parser is defensive but *silent*, so support contacts
 * quietly reverting to the compiled-in defaults would not surface as an error
 * anywhere.
 *
 * `api_base_url` is stored explicitly rather than left null to be derived. The
 * derivation depends on config('app.url') being correct on the server, and a
 * wrong-but-non-empty base URL is worse than a missing one: the app would accept
 * it and talk to the wrong host. Copying the known-good value removes that
 * dependency for the one app currently reading this.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fawateer = DB::table('device_apps')->where('name', 'Fawateer')->first();

        if ($fawateer === null) {
            return;
        }

        // Only seed a row nobody has edited yet, so re-running cannot clobber
        // values an operator has since changed from the dashboard.
        if ($fawateer->latest_version !== null) {
            return;
        }

        DB::table('device_apps')->where('id', $fawateer->id)->update([
            'latest_version' => '1.0.0',
            'api_base_url' => 'https://api.evotech-sys.com/api/fawateer',
            'support_email' => 'mohamad.hasan.it.96@gmail.com',
            'support_whatsapp' => '963959027196',
            'support_telegram' => 'https://t.me/+963959027196',
            // The live file has {} and [] for these; null renders identically.
            'downloads' => null,
            'update_notes' => null,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('device_apps')->where('name', 'Fawateer')->update([
            'latest_version' => null,
            'api_base_url' => null,
            'support_email' => null,
            'support_whatsapp' => null,
            'support_telegram' => null,
        ]);
    }
};
