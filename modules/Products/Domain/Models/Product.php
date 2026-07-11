<?php

namespace Modules\Products\Domain\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Products\Database\Factories\ProductFactory;
use Modules\Products\Domain\Enums\ProductStatus;

/**
 * A product in the EVOTECH catalog. Platform-global reference data (not tenant-scoped);
 * the single source consumed by both the marketing website and the dashboard.
 * Translatable fields (name/tagline/description) are stored as JSON `{ "ar": ..., "en": ... }`.
 *
 * @property int $id
 * @property string $slug
 * @property array<string, string> $name
 * @property array<string, string> $tagline
 * @property array<string, string> $description
 * @property string $icon
 * @property array<int, string> $platforms
 * @property bool $is_featured
 * @property ProductStatus $status
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Plan> $plans
 * @property-read Collection<int, Plan> $activePlans
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name',
        'tagline',
        'description',
        'icon',
        'platforms',
        'is_featured',
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
            'tagline' => 'array',
            'description' => 'array',
            'platforms' => 'array',
            'is_featured' => 'boolean',
            'status' => ProductStatus::class,
            'sort_order' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return HasMany<Plan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class)->orderBy('sort_order');
    }

    /**
     * Only active plans — used when presenting the catalog.
     *
     * @return HasMany<Plan, $this>
     */
    public function activePlans(): HasMany
    {
        return $this->plans()->where('status', ProductStatus::Active->value);
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
