<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The `ledger` product (دفتر حسابات) description stops listing the old→new lira
 * conversion as a Pro feature: old notes were withdrawn nationwide and the app made
 * the conversion free (Accounting-Book, owner decision 2026-10-07). It names the
 * multi-device plan instead, which now exists (`yearly_multi`).
 *
 * Guarded on the exact text 2026_10_06_130000 wrote, so a description an operator
 * edited since is left alone. Idempotent. `down()` is a no-op: the old text would be
 * wrong again.
 */
return new class extends Migration
{
    private const PREVIOUS_AR = 'سجّل ديون زبائنك ودفعاتهم، واعرف رصيد كل زبون فوراً. مجاني بلا حدود ويعمل بلا إنترنت، وبياناتك تبقى على هاتفك. Pro يضيف تذكير واتساب، ونسخاً احتياطياً إلى Google Drive، وتحويل الليرة الجديدة.';

    private const CURRENT_AR = 'سجّل ديون زبائنك ودفعاتهم، واعرف رصيد كل زبون فوراً. مجاني بلا حدود ويعمل بلا إنترنت، وبياناتك تبقى على هاتفك. Pro يضيف تذكير واتساب، ونسخاً احتياطياً إلى Google Drive، ودفتراً واحداً على عدّة هواتف في المحل.';

    private const CURRENT_EN = "Record the debts and payments of your customers and see every balance instantly. Free with no limits, works offline, and your data stays on your phone. Pro adds WhatsApp reminders, Google Drive backup, and one ledger across the shop's phones.";

    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        $product = DB::table('products')->where('slug', 'ledger')->first();

        if ($product === null) {
            return;
        }

        $description = json_decode((string) $product->description, true);

        if (! is_array($description) || ($description['ar'] ?? null) !== self::PREVIOUS_AR) {
            return;
        }

        DB::table('products')->where('id', $product->id)->update([
            'description' => json_encode(
                ['ar' => self::CURRENT_AR, 'en' => self::CURRENT_EN],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            'updated_at' => now(),
        ]);
    }

    public function down(): void {}
};
