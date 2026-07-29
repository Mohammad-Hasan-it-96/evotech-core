<?php

namespace Modules\DeviceSubscriptions\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One append-only entry in a business's change log (ADR 0011).
 *
 * Append-only by construction: there is a `created_at` and no `updated_at`. The
 * two clocks are kept apart deliberately — `seq` is the server cursor, never used
 * for conflict resolution; `authored_hlc` is the device clock, never used for
 * paging. See the migration header for the full contract.
 *
 * @property int $id
 * @property int $device_business_id
 * @property int $seq
 * @property string $row_uuid
 * @property string $table_name
 * @property string $op
 * @property string $origin_device
 * @property string $authored_hlc
 * @property string $idempotency_key
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $created_at
 * @property-read DeviceBusiness $business
 */
class DeviceChange extends Model
{
    public const OP_UPSERT = 'upsert';

    public const OP_DELETE = 'delete';

    /** Append-only: manage created_at only, there is no updated_at column. */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'device_business_id',
        'seq',
        'row_uuid',
        'table_name',
        'op',
        'origin_device',
        'authored_hlc',
        'idempotency_key',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The per-row idempotency key (§F2): the row's uuid plus its authored HLC.
     * The same edit re-pushed is byte-identical and no-ops; a later edit to the
     * same row carries a new HLC and is a genuinely new change.
     */
    public static function idempotencyKey(string $rowUuid, string $authoredHlc): string
    {
        return $rowUuid.'|'.$authoredHlc;
    }

    /**
     * @return BelongsTo<DeviceBusiness, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(DeviceBusiness::class, 'device_business_id');
    }
}
