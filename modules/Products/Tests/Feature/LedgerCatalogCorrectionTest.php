<?php

namespace Modules\Products\Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Domain\Enums\ProductStatus;
use Modules\Products\Domain\Models\Plan;
use Modules\Products\Domain\Models\Product;
use Modules\Subscriptions\Domain\Models\Subscription;
use Tests\TestCase;

/**
 * Migration 2026_10_06_130000: the `ledger` product (the دفتر حسابات app) loses the
 * placeholder copy and the company plans nobody sells — but never a deliberate edit,
 * and never a plan a subscription holds.
 */
class LedgerCatalogCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private const PLACEHOLDER_AR = 'سجّل المقبوضات والمدفوعات وتابع أرصدة العملاء والموردين.';

    /** The state production is in: the catalog as first seeded. */
    private function seedPlaceholderLedger(string $descriptionAr = self::PLACEHOLDER_AR): Product
    {
        $product = Product::factory()->create([
            'slug' => 'ledger',
            'name' => ['ar' => 'دفتر الحسابات', 'en' => 'Ledger'],
            'tagline' => ['ar' => 'دفتر حساباتك في جيبك', 'en' => 'Your accounts, in your pocket'],
            'description' => ['ar' => $descriptionAr, 'en' => 'Record income and expenses, track customer and supplier balances.'],
            'platforms' => ['Android', 'iOS'],
        ]);
        Plan::factory()->for($product)->create(['price' => 15, 'sort_order' => 0]);
        Plan::factory()->for($product)->create(['price' => 39, 'sort_order' => 1, 'is_popular' => true]);

        return $product;
    }

    private function runMigration(): void
    {
        $migration = require base_path(
            'modules/Products/Database/Migrations/2026_10_06_130000_correct_ledger_product_for_daftar_app.php'
        );
        $this->assertInstanceOf(Migration::class, $migration);
        // Reflection because the base Migration class does not declare up().
        (new \ReflectionMethod($migration, 'up'))->invoke($migration);
    }

    public function test_the_placeholder_is_corrected_and_the_fake_plans_leave_the_public_catalog(): void
    {
        $this->seedPlaceholderLedger();

        $this->runMigration();
        $this->runMigration(); // idempotent

        $this->getJson('/api/v1/products/ledger')
            ->assertOk()
            ->assertJsonPath('data.name.ar', 'دفتر حسابات')
            ->assertJsonPath('data.platforms', ['Android'])
            ->assertJsonCount(0, 'data.plans');

        $description = $this->getJson('/api/v1/products/ledger')->json('data.description.ar');
        $this->assertIsString($description);
        $this->assertStringContainsString('ديون زبائنك', $description);
        $this->assertStringNotContainsString('الموردين', $description);

        // Deactivated, not deleted.
        $this->assertSame(2, Plan::query()->count());
        $this->assertSame(0, Plan::query()->where('status', ProductStatus::Active->value)->count());
    }

    public function test_a_deliberate_edit_to_the_description_is_kept(): void
    {
        $this->seedPlaceholderLedger('وصف كتبه المشغّل');

        $this->runMigration();

        $this->assertSame('وصف كتبه المشغّل', Product::query()->where('slug', 'ledger')->sole()->description['ar']);
    }

    public function test_a_plan_that_a_subscription_holds_stays_active(): void
    {
        $product = $this->seedPlaceholderLedger();
        $held = $product->plans()->where('price', 15)->sole();
        Subscription::factory()->create(['plan_id' => $held->id]);

        $this->runMigration();

        $this->assertSame(ProductStatus::Active, $held->fresh()?->status);
        $this->assertSame(ProductStatus::Inactive, $product->plans()->where('price', 39)->sole()->status);
    }

    public function test_other_products_are_untouched(): void
    {
        $this->seedPlaceholderLedger();
        $other = Product::factory()->create(['slug' => 'invoices']);
        Plan::factory()->for($other)->create(['price' => 15]);

        $this->runMigration();

        $this->assertSame(1, $other->plans()->where('status', ProductStatus::Active->value)->count());
    }
}
