<?php

namespace Modules\Products\Domain\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Core\Domain\Concerns\HasUuid;
use Modules\Products\Database\Factories\PlanFactory;
use Modules\Products\Domain\Enums\BillingPeriod;
use Modules\Products\Domain\Enums\ProductStatus;

/**
 * A pricing plan / edition of a product (e.g. Basic, Pro). Subscriptions reference a plan.
 *
 * @property int $id
 * @property string $uuid
 * @property int $product_id
 * @property array<string, string> $name
 * @property string $price
 * @property string $currency
 * @property BillingPeriod $billing_period
 * @property array<int, array<string, string>> $features
 * @property bool $is_popular
 * @property ProductStatus $status
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    use HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'name',
        'price',
        'currency',
        'billing_period',
        'features',
        'is_popular',
        'status',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'price' => 'decimal:2',
            'features' => 'array',
            'is_popular' => 'boolean',
            'billing_period' => BillingPeriod::class,
            'status' => ProductStatus::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected static function newFactory(): PlanFactory
    {
        return PlanFactory::new();
    }
}
