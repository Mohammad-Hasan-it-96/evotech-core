<?php

namespace Modules\Downloads\Domain\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Core\Domain\Concerns\HasUuid;
use Modules\Downloads\Database\Factories\ReleaseFactory;
use Modules\Downloads\Domain\Enums\ReleaseChannel;
use Modules\Downloads\Domain\Enums\ReleaseStatus;
use Modules\Products\Domain\Models\Product;

/**
 * A versioned release of a product on a channel. Groups one downloadable
 * artifact per platform. Only a Published release is visible to products and
 * deliverable (ADR 0008). References the Products catalog (reference data).
 *
 * @property int $id
 * @property string $uuid
 * @property int $product_id
 * @property ReleaseChannel $channel
 * @property string $version
 * @property string|null $name
 * @property string|null $notes
 * @property ReleaseStatus $status
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Product $product
 * @property-read Collection<int, Artifact> $artifacts
 */
class Release extends Model
{
    /** @use HasFactory<ReleaseFactory> */
    use HasFactory;

    use HasUuid;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'channel',
        'version',
        'name',
        'notes',
        'status',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ReleaseChannel::class,
            'status' => ReleaseStatus::class,
            'published_at' => 'datetime',
        ];
    }

    public function isPublished(): bool
    {
        return $this->status->isDownloadable();
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<Artifact, $this>
     */
    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class);
    }

    protected static function newFactory(): ReleaseFactory
    {
        return ReleaseFactory::new();
    }
}
