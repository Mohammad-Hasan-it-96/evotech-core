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
use Modules\Downloads\Database\Factories\ArtifactFactory;
use Modules\Downloads\Domain\Enums\Platform;

/**
 * A downloadable file belonging to a release, one per platform *and variant*.
 * Stored on the private `downloads` disk and delivered only via short-lived signed
 * URLs (ADR 0008). Carries a SHA-256 checksum so a device can verify integrity.
 *
 * `variant` distinguishes builds of the same platform — Android's `arm64-v8a` and
 * `armeabi-v7a`. An empty string means universal: one build that installs
 * anywhere, which is what every artifact was before variants existed.
 *
 * @property int $id
 * @property string $uuid
 * @property int $release_id
 * @property Platform $platform
 * @property string $variant
 * @property string $disk
 * @property string $path
 * @property string $filename
 * @property int $size
 * @property string $checksum_sha256
 * @property string|null $content_type
 * @property int $download_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Release $release
 * @property-read Collection<int, DownloadEvent> $events
 */
class Artifact extends Model
{
    /** @use HasFactory<ArtifactFactory> */
    use HasFactory;

    use HasUuid;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'release_id',
        'platform',
        'variant',
        'disk',
        'path',
        'filename',
        'size',
        'checksum_sha256',
        'content_type',
        'download_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'size' => 'integer',
            'download_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /**
     * @return HasMany<DownloadEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(DownloadEvent::class);
    }

    protected static function newFactory(): ArtifactFactory
    {
        return ArtifactFactory::new();
    }
}
