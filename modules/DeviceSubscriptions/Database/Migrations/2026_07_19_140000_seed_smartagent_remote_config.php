<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Carries Smart-Agent's remote config in from the Google Drive file its shipped
 * builds read, so `/api/smartagent/remote-config` answers with the same values the
 * app is already acting on.
 *
 * ## Why `api_base_url` is the legacy host, not this platform
 *
 * Smart-Agent's users are on the legacy backend. Its parser applies `base_url`
 * *destructively* — unlike Fawateer, which keeps its current value when the field
 * is blank, Smart-Agent resets the persisted URL to its compiled-in default. So the
 * moment a build points here, whatever is in this column becomes where every device
 * talks.
 *
 * Seeding the derived `…/api/smartagent` URL would therefore migrate the whole app
 * to a backend that does not serve its users, silently, on the next launch.
 * Preserving the legacy host means pointing a build here changes *nothing* about
 * where it talks — and the migration to this platform becomes a deliberate one-field
 * edit, made when someone decides to make it.
 *
 * ## Why this is inert until a new build ships
 *
 * Shipped builds read a hardcoded Drive URL, and Drive serves bytes — it cannot
 * redirect here. Nothing reads this until a release points at
 * `/config/smartagent.json`. Seeding now means that release is a one-line change
 * with no config scramble behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $app = DB::table('device_apps')->where('name', 'SmartAgent')->first();

        if ($app === null || $app->latest_version !== null) {
            return;
        }

        DB::table('device_apps')->where('id', $app->id)->update([
            // Values copied verbatim from the live Drive file.
            'latest_version' => '1.1.1',
            'api_base_url' => 'https://harrypotter.foodsalebot.com/api',
            'downloads' => json_encode([
                'arm64-v8a' => 'https://harrypotter.foodsalebot.com/downloads/app-arm64-v8a-release.apk',
                'armeabi-v7a' => 'https://harrypotter.foodsalebot.com/downloads/app-armeabi-v7a-release.apk',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'update_notes' => json_encode([
                'حل مشكلة فاتورة تحوي أكثر من 20  صنف دواء',
                'لتحميل التحديث الجديد اضغط على زر تحميل التحديث سيفتح المتصفح لديك تلقائياً',
                'سيقوم المتصفح بتنزيل النسخة الأحدث مباشرةً، قم بفتح النسخة الحديثة لتثبيتها',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            // Its own inbox — deliberately not Fawateer's.
            'support_email' => 'smart.agent.app.support@gmail.com',
            'support_whatsapp' => '963959027196',
            'support_telegram' => 'https://t.me/+963959027196',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('device_apps')->where('name', 'SmartAgent')->update([
            'latest_version' => null,
            'api_base_url' => null,
            'downloads' => null,
            'update_notes' => null,
            'support_email' => null,
            'support_whatsapp' => null,
            'support_telegram' => null,
        ]);
    }
};
