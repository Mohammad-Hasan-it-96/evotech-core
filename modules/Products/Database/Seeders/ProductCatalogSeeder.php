<?php

namespace Modules\Products\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Products\Domain\Enums\BillingPeriod;
use Modules\Products\Domain\Enums\ProductStatus;
use Modules\Products\Domain\Models\Product;

/**
 * Reference seeder: the real EVOTECH product catalog. Idempotent and safe to run
 * in any environment (constitution §5). Mirrors the marketing site content so the
 * API can become the single source (Phase 3.5).
 */
class ProductCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalog() as $order => $data) {
            $product = Product::query()->updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'name' => $data['name'],
                    'tagline' => $data['tagline'],
                    'description' => $data['description'],
                    'icon' => $data['icon'],
                    'platforms' => $data['platforms'],
                    'is_featured' => $data['is_featured'],
                    'status' => ProductStatus::Active,
                    'sort_order' => $order,
                ],
            );

            foreach ($data['plans'] as $planOrder => $plan) {
                $product->plans()->updateOrCreate(
                    ['sort_order' => $planOrder],
                    [
                        'name' => $plan['name'],
                        'price' => $plan['price'],
                        'currency' => 'USD',
                        'billing_period' => BillingPeriod::Monthly,
                        'features' => $plan['features'],
                        'is_popular' => $plan['is_popular'] ?? false,
                        'status' => ProductStatus::Active,
                    ],
                );
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalog(): array
    {
        $basic = fn (float $price, array $features, bool $popular = false): array => [
            'name' => ['ar' => 'الأساسية', 'en' => 'Basic'],
            'price' => $price,
            'features' => $features,
            'is_popular' => false,
        ];
        $pro = fn (float $price, array $features): array => [
            'name' => ['ar' => 'الاحترافية', 'en' => 'Pro'],
            'price' => $price,
            'features' => $features,
            'is_popular' => true,
        ];

        return [
            [
                'slug' => 'smart-delegate',
                'name' => ['ar' => 'المندوب الذكي', 'en' => 'Smart Delegate'],
                'tagline' => ['ar' => 'إدارة المندوبين والتحصيل ميدانياً', 'en' => 'Field sales & collection, managed'],
                'description' => ['ar' => 'تطبيق يُدير المندوبين والطلبات والتحصيل في الميدان مع تتبّع فوري وتقارير دقيقة.', 'en' => 'Manage field reps, orders and collections with live tracking and accurate reports.'],
                'icon' => 'truck',
                'platforms' => ['Android', 'iOS'],
                'is_featured' => true,
                'plans' => [
                    $basic(29, [['ar' => 'حتى 3 مندوبين', 'en' => 'Up to 3 reps']]),
                    $pro(79, [['ar' => 'مندوبون غير محدودين', 'en' => 'Unlimited reps'], ['ar' => 'تقارير متقدمة', 'en' => 'Advanced reports']]),
                ],
            ],
            [
                'slug' => 'invoices',
                'name' => ['ar' => 'فواتير', 'en' => 'Invoices'],
                'tagline' => ['ar' => 'فوترة احترافية بلمسة زر', 'en' => 'Professional invoicing in a tap'],
                'description' => ['ar' => 'أنشئ فواتير وعروض أسعار احترافية وتابع المدفوعات والذمم.', 'en' => 'Create professional invoices and quotes, track payments and receivables.'],
                'icon' => 'receipt',
                'platforms' => ['Android', 'iOS', 'Web'],
                'is_featured' => true,
                'plans' => [
                    $basic(19, [['ar' => 'حتى 100 فاتورة/شهر', 'en' => 'Up to 100 invoices/mo']]),
                    $pro(49, [['ar' => 'فواتير غير محدودة', 'en' => 'Unlimited invoices'], ['ar' => 'ضرائب وخصومات', 'en' => 'Taxes & discounts']]),
                ],
            ],
            [
                // The دفتر حسابات app (device app daftar_hesabat). Its prices are the
                // device plans at /api/daftar/getPlans, not company plans, so it has
                // none here (see migration 2026_10_06_130000).
                'slug' => 'ledger',
                'name' => ['ar' => 'دفتر حسابات', 'en' => 'Daftar Hesabat'],
                'tagline' => ['ar' => 'دفتر الديون صار على موبايلك', 'en' => 'The debt notebook for your shop, on your phone'],
                'description' => ['ar' => 'سجّل ديون زبائنك ودفعاتهم، واعرف رصيد كل زبون فوراً. مجاني بلا حدود ويعمل بلا إنترنت، وبياناتك تبقى على هاتفك. Pro يضيف تذكير واتساب، ونسخاً احتياطياً إلى Google Drive، وتحويل الليرة الجديدة.', 'en' => 'Record the debts and payments of your customers and see every balance instantly. Free with no limits, works offline, and your data stays on your phone. Pro adds WhatsApp reminders, Google Drive backup and the new-lira conversion.'],
                'icon' => 'book',
                'platforms' => ['Android'],
                'is_featured' => true,
                'plans' => [],
            ],
            [
                'slug' => 'restaurant',
                'name' => ['ar' => 'نظام المطاعم', 'en' => 'Restaurant Suite'],
                'tagline' => ['ar' => 'منيو + لوحة طلبات + تطبيق', 'en' => 'Menu + orders dashboard + app'],
                'description' => ['ar' => 'نظام متكامل للمطاعم: منيو رقمي، لوحة طلبات لحظية، وتطبيق للعملاء.', 'en' => 'A complete restaurant system: digital menu, real-time orders board, and a customer app.'],
                'icon' => 'utensils',
                'platforms' => ['Web', 'Android', 'iOS'],
                'is_featured' => true,
                'plans' => [
                    $basic(49, [['ar' => 'فرع واحد', 'en' => '1 branch']]),
                    $pro(99, [['ar' => 'فروع متعددة', 'en' => 'Multiple branches'], ['ar' => 'تطبيق عملاء', 'en' => 'Customer app']]),
                ],
            ],
            [
                'slug' => 'pharmacy-warehouse',
                'name' => ['ar' => 'مستودعات الأدوية', 'en' => 'Pharma Warehouse'],
                'tagline' => ['ar' => 'إدارة مستودعات الأدوية بدقة', 'en' => 'Precise pharmaceutical warehousing'],
                'description' => ['ar' => 'تتبّع الدفعات وتواريخ الصلاحية وإدارة المخزون وتطبيق جرد وتوزيع.', 'en' => 'Batch & expiry tracking, inventory management, and a field stock-taking app.'],
                'icon' => 'pill',
                'platforms' => ['Web', 'Android'],
                'is_featured' => false,
                'plans' => [
                    $basic(89, [['ar' => 'مستودع واحد', 'en' => '1 warehouse']]),
                    $pro(199, [['ar' => 'مستودعات متعددة', 'en' => 'Multiple warehouses'], ['ar' => 'تنبيهات صلاحية', 'en' => 'Expiry alerts']]),
                ],
            ],
        ];
    }
}
