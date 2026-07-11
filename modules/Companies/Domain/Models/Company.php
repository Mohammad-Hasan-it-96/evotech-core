<?php

namespace Modules\Companies\Domain\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Companies\Database\Factories\CompanyFactory;
use Modules\Companies\Domain\Enums\CompanyStatus;
use Modules\Core\Domain\Concerns\HasUuid;

/**
 * A company is a tenant organization on the platform (a business that subscribes
 * to EVOTECH products). Companies are platform-global — EVOTECH staff manage all
 * of them — so this model is NOT itself company-scoped.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property CompanyStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
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
        'status' => CompanyStatus::Active->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
        ];
    }

    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }
}
