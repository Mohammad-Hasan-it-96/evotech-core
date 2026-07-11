<?php

namespace Modules\Licenses\Domain\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Companies\Domain\Models\Company;
use Modules\Core\Domain\Concerns\HasUuid;
use Modules\Licenses\Database\Factories\LicenseFactory;
use Modules\Licenses\Domain\Enums\LicenseStatus;
use Modules\Subscriptions\Domain\Models\Subscription;

/**
 * A machine-readable credential proving a company's entitlement to a product,
 * derived from a subscription. Composition module — references Subscriptions +
 * Companies. Holds up to `max_activations` concurrent device/domain activations.
 *
 * @property int $id
 * @property string $uuid
 * @property int $subscription_id
 * @property int $company_id
 * @property string $key
 * @property LicenseStatus $status
 * @property int $max_activations
 * @property Carbon|null $expires_at
 * @property Carbon $issued_at
 * @property Carbon|null $revoked_at
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Subscription $subscription
 * @property-read Company $company
 * @property-read Collection<int, LicenseEvent> $events
 * @property-read Collection<int, LicenseActivation> $activations
 * @property-read Collection<int, LicenseActivation> $activeActivations
 * @property-read int|null $active_activations_count
 */
class License extends Model
{
    /** @use HasFactory<LicenseFactory> */
    use HasFactory;

    use HasUuid;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'subscription_id',
        'company_id',
        'key',
        'status',
        'max_activations',
        'expires_at',
        'issued_at',
        'revoked_at',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LicenseStatus::class,
            'max_activations' => 'integer',
            'expires_at' => 'datetime',
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** Whether the license currently grants entitlement (status + expiry). */
    public function isCurrentlyValid(): bool
    {
        return $this->status->isUsable()
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<LicenseEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(LicenseEvent::class);
    }

    /**
     * @return HasMany<LicenseActivation, $this>
     */
    public function activations(): HasMany
    {
        return $this->hasMany(LicenseActivation::class);
    }

    /**
     * Activations currently occupying a slot (not deactivated).
     *
     * @return HasMany<LicenseActivation, $this>
     */
    public function activeActivations(): HasMany
    {
        return $this->activations()->whereNull('revoked_at');
    }

    protected static function newFactory(): LicenseFactory
    {
        return LicenseFactory::new();
    }
}
