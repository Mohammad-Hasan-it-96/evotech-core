<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The `ledger` product is the دفتر حسابات app (device app `daftar_hesabat`), but the
 * catalog still carried the placeholder written before the app existed:
 *  - an accounting-style description (income/expenses, suppliers) for what is a shop
 *    debt notebook;
 *  - iOS, which has no build;
 *  - company plans "Basic $15 / Pro $39 monthly" that nothing sells. The app's real
 *    prices are the device plans served at /api/daftar/getPlans.
 * The marketing site renders all of that, on /products/ledger and /pricing.
 *
 * Products have no edit API or dashboard screen, so the correction has to ship as a
 * migration, which the deploy runs (it never runs db:seed).
 *
 * Guarded so it can never clobber a deliberate edit:
 *  - the text is replaced only while it is still the seeded placeholder;
 *  - the placeholder plans are set **inactive**, never deleted (subscriptions
 *    reference plans with restrictOnDelete). A plan that any subscription
 *    references is left as it is.
 */
return new class extends Migration
{
    private const PLACEHOLDER_DESCRIPTION_AR = 'سجّل المقبوضات والمدفوعات وتابع أرصدة العملاء والموردين.';

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
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        if (is_array($description) && ($description['ar'] ?? null) === self::PLACEHOLDER_DESCRIPTION_AR) {
            DB::table('products')->where('id', $product->id)->update([
                'name' => json_encode(['ar' => 'دفتر حسابات', 'en' => 'Daftar Hesabat'], $flags),
                'tagline' => json_encode(['ar' => 'دفتر الديون صار على موبايلك', 'en' => 'The debt notebook for your shop, on your phone'], $flags),
                'description' => json_encode([
                    'ar' => 'سجّل ديون زبائنك ودفعاتهم، واعرف رصيد كل زبون فوراً. مجاني بلا حدود ويعمل بلا إنترنت، وبياناتك تبقى على هاتفك. Pro يضيف تذكير واتساب، ونسخاً احتياطياً إلى Google Drive، وتحويل الليرة الجديدة.',
                    'en' => 'Record the debts and payments of your customers and see every balance instantly. Free with no limits, works offline, and your data stays on your phone. Pro adds WhatsApp reminders, Google Drive backup and the new-lira conversion.',
                ], $flags),
                'platforms' => json_encode(['Android'], $flags),
                'updated_at' => now(),
            ]);
        }

        $referenced = Schema::hasTable('subscriptions')
            ? DB::table('subscriptions')->distinct()->pluck('plan_id')->all()
            : [];

        DB::table('plans')
            ->where('product_id', $product->id)
            ->where('status', 'active')
            ->where('billing_period', 'monthly')
            ->whereIn('price', [15, 39])
            ->whereNotIn('id', $referenced)
            ->update(['status' => 'inactive', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Intentionally irreversible: restoring a known-wrong description and
        // re-activating plans nobody sells would only put the misinformation back.
    }
};
