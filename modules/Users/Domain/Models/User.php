<?php

namespace Modules\Users\Domain\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Modules\Core\Domain\Concerns\HasUuid;
use Modules\Core\Domain\Contracts\HasCompany;
use Modules\Users\Database\Factories\UserFactory;

/**
 * Platform user (staff / customer-portal identity). Owned by the Users module.
 * Authentication flows live in the Auth module; this is the identity + profile.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $company_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class User extends Authenticatable implements HasCompany
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use HasUuid;
    use Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function companyId(): ?int
    {
        return $this->company_id;
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
