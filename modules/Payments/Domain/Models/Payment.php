<?php

namespace Modules\Payments\Domain\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Core\Domain\Concerns\HasUuid;
use Modules\Payments\Database\Factories\PaymentFactory;
use Modules\Payments\Domain\Enums\PaymentMethod;

/**
 * A recorded receipt against an invoice (ADR 0006). Immutable financial record.
 *
 * @property int $id
 * @property string $uuid
 * @property int $invoice_id
 * @property string $amount
 * @property string $currency
 * @property PaymentMethod $method
 * @property string $gateway
 * @property string|null $reference
 * @property Carbon $paid_at
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Invoice $invoice
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    use HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'invoice_id',
        'amount',
        'currency',
        'method',
        'gateway',
        'reference',
        'paid_at',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'method' => PaymentMethod::class,
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }
}
