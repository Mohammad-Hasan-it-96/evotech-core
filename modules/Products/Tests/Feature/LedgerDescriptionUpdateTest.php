<?php

namespace Modules\Products\Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Database\Seeders\ProductCatalogSeeder;
use Modules\Products\Domain\Models\Product;
use Tests\TestCase;

/**
 * Migration 2026_10_07_210000: the ledger (دفتر حسابات) description stops listing
 * the lira conversion as Pro (it is free since old notes were withdrawn) and names
 * the multi-device plan. Only the exact previous text is replaced.
 */
class LedgerDescriptionUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = 'modules/Products/Database/Migrations/2026_10_07_210000_update_ledger_description_pro_features.php';

    private const PREVIOUS_AR = 'سجّل ديون زبائنك ودفعاتهم، واعرف رصيد كل زبون فوراً. مجاني بلا حدود ويعمل بلا إنترنت، وبياناتك تبقى على هاتفك. Pro يضيف تذكير واتساب، ونسخاً احتياطياً إلى Google Drive، وتحويل الليرة الجديدة.';

    /** Spelled out here, not read from the migration, so a typo there fails the test. */
    private const CURRENT_AR = 'سجّل ديون زبائنك ودفعاتهم، واعرف رصيد كل زبون فوراً. مجاني بلا حدود ويعمل بلا إنترنت، وبياناتك تبقى على هاتفك. Pro يضيف تذكير واتساب، ونسخاً احتياطياً إلى Google Drive، ودفتراً واحداً على عدّة هواتف في المحل.';

    private const CURRENT_EN = "Record the debts and payments of your customers and see every balance instantly. Free with no limits, works offline, and your data stays on your phone. Pro adds WhatsApp reminders, Google Drive backup, and one ledger across the shop's phones.";

    private function migration(): Migration
    {
        $migration = require base_path(self::PATH);
        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    private function applyUp(Migration $migration): void
    {
        (new \ReflectionMethod($migration, 'up'))->invoke($migration);
    }

    private function ledger(string $descriptionAr): void
    {
        Product::factory()->create([
            'slug' => 'ledger',
            'description' => ['ar' => $descriptionAr, 'en' => 'old'],
        ]);
    }

    public function test_the_previous_text_is_replaced_and_rerunning_changes_nothing(): void
    {
        $this->ledger(self::PREVIOUS_AR);
        $migration = $this->migration();

        $this->applyUp($migration);
        $this->applyUp($migration);

        $this->getJson('/api/v1/products/ledger')
            ->assertOk()
            ->assertJsonPath('data.description.ar', self::CURRENT_AR)
            ->assertJsonPath('data.description.en', self::CURRENT_EN);
        $this->assertStringNotContainsString('الليرة', self::CURRENT_AR);
    }

    public function test_an_operator_edit_is_kept(): void
    {
        $this->ledger('وصف كتبه المشغّل');

        $this->applyUp($this->migration());

        $this->assertSame('وصف كتبه المشغّل', Product::query()->where('slug', 'ledger')->sole()->description['ar']);
    }

    public function test_a_fresh_seed_carries_the_same_text(): void
    {
        $this->seed(ProductCatalogSeeder::class);
        $migration = $this->migration();

        $description = Product::query()->where('slug', 'ledger')->sole()->description;
        $this->assertSame(self::CURRENT_AR, $description['ar']);
        $this->assertSame(self::CURRENT_EN, $description['en']);
    }
}
