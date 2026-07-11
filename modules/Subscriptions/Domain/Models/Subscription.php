<?php

namespace Modules\Subscriptions\Domain\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Companies\Domain\Models\Company;
use Modules\Core\Domain\Concerns\HasUuid;
use Modules\Products\Domain\Models\Plan;
use Modules\Subscriptions\Database\Factories\SubscriptionFactory;
use Modules\Subscriptions\Domain\Enums\IdentifierType;
use Modules\Subscriptions\Domain\Enums\SubscriptionStatus;

/**
 * Links a company (subscriber) to a plan for a period. Admin-managed by EVOTECH
 * staff. Subscriptions is a composition module — it references Companies + Products.
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $plan_id
 * @property IdentifierType|null $identifier_type
 * @property string|null $identifier_value
 * @property SubscriptionStatus $status
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property bool $auto_renew
 * @property string $price
 * @property string $currency
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Company $company
 * @property-read Plan $plan
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    use HasUuid;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'plan_id',
        'identifier_type',
        'identifier_value',
        'status',
        'starts_at',
        'ends_at',
        'auto_renew',
        'price',
        'currency',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'identifier_type' => IdentifierType::class,
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'auto_renew' => 'boolean',
            'price' => 'decimal:2',
        ];
    }

    public function isCurrentlyActive(): bool
    {
        return $this->status === SubscriptionStatus::Active
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    protected static function newFactory(): SubscriptionFactory
    {
        return SubscriptionFactory::new();
    }
}
