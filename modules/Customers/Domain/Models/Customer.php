<?php

namespace Modules\Customers\Domain\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Core\Domain\Concerns\BelongsToCompany;
use Modules\Core\Domain\Concerns\HasUuid;
use Modules\Customers\Database\Factories\CustomerFactory;
use Modules\Customers\Domain\Enums\CustomerStatus;

/**
 * A customer belongs to a company (the tenant). Tenant-scoped: the BelongsToCompany
 * trait auto-filters queries by the current company and fills company_id on create.
 * `company_id` is intentionally NOT fillable — it is set by the tenant context, never
 * by client input.
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property CustomerStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Customer extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'status',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => CustomerStatus::Active->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
        ];
    }

    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }
}
