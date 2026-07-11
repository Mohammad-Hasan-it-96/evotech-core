<?php

namespace Modules\Core\Domain\Concerns;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

/**
 * Gives a model a time-ordered UUID (v7) public identifier, used as the route key.
 * Internal PK stays a bigint `id`; the `uuid` column is what the API and URLs expose
 * (constitution §5 — hybrid identifier strategy).
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->getAttribute('uuid'))) {
                $model->setAttribute('uuid', Uuid::uuid7()->toString());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
