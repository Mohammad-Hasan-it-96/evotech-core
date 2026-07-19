<?php

namespace Modules\DeviceSubscriptions\Domain\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Domain\Concerns\HasUuid;

/**
 * A shipped consumer app (Fawateer, SmartAgent) and its selling terms.
 *
 * Deliberately not tenant-scoped: these apps are sold to individual device owners,
 * not to Companies, so there is no company_id to scope by — same reasoning as
 * DeviceSubscription itself.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string $label
 * @property int $trial_days
 * @property bool $uses_shared_plans
 * @property int|null $product_id
 */
class DeviceApp extends Model
{
    use HasUuid;

    protected $fillable = [
        'name',
        'slug',
        'label',
        'trial_days',
        'uses_shared_plans',
        'product_id',
    ];

    protected function casts(): array
    {
        return [
            'trial_days' => 'int',
            'uses_shared_plans' => 'bool',
        ];
    }

    /** @return HasMany<DevicePlan, $this> */
    public function plans(): HasMany
    {
        return $this->hasMany(DevicePlan::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The catalog this app sells, or null when it defers to the shared list.
     *
     * Null and an empty collection are different answers and callers must keep them
     * apart: null means "ask the shared catalog", empty means "this app genuinely
     * sells nothing". Collapsing them would hand an app the wrong prices.
     *
     * @return Collection<int, DevicePlan>|null
     */
    public function ownPlans(): ?Collection
    {
        return $this->uses_shared_plans ? null : $this->plans()->get();
    }
}
