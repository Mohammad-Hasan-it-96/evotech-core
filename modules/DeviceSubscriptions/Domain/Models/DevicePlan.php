<?php

namespace Modules\DeviceSubscriptions\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Domain\Concerns\HasUuid;

/**
 * One purchasable plan. `device_app_id` null = the shared catalog.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $device_app_id
 * @property string $plan_key
 * @property string $title
 * @property string|null $description
 * @property int $duration_months
 * @property string $price
 * @property string|null $price_after_discount
 * @property bool $enabled
 * @property bool $recommended
 * @property int $sort_order
 */
class DevicePlan extends Model
{
    use HasUuid;

    protected $fillable = [
        'device_app_id',
        'plan_key',
        'title',
        'description',
        'duration_months',
        'price',
        'price_after_discount',
        'enabled',
        'recommended',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'duration_months' => 'int',
            'price' => 'decimal:2',
            'price_after_discount' => 'decimal:2',
            'enabled' => 'bool',
            'recommended' => 'bool',
            'sort_order' => 'int',
        ];
    }

    /** @return BelongsTo<DeviceApp, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(DeviceApp::class, 'device_app_id');
    }

    /**
     * The plan exactly as `getPlans` has always returned it.
     *
     * This shape is a contract with builds already on customers' phones, so it is
     * pinned here rather than left to an API Resource: `id` is the plan_key (not the
     * uuid) because that is what the app sends back and what device rows store.
     *
     * @return array<string, mixed>
     */
    public function toLegacyArray(): array
    {
        return [
            'id' => $this->plan_key,
            'title' => $this->title,
            'duration_months' => $this->duration_months,
            'price' => self::number($this->price),
            'price_after_discount' => self::number($this->price_after_discount),
            'enabled' => $this->enabled,
            'recommended' => $this->recommended,
            'description' => $this->description,
        ];
    }

    /**
     * Emits a whole price as an int so the JSON stays `12` rather than becoming
     * `12.0` or the string `"12.00"` that a decimal cast would produce. The shipped
     * parsers were written against integer prices and this is not the release to
     * find out how strictly they read them.
     */
    private static function number(mixed $value): int|float|null
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return $float === floor($float) ? (int) $float : $float;
    }
}
