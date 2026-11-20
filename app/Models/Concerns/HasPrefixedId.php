<?php

namespace App\Models\Concerns;

use App\Pipstream\Ids;

/** Text primary keys shaped like the engine's ids (usr_..., ch_..., ba_...). */
trait HasPrefixedId
{
    public static function bootHasPrefixedId(): void
    {
        static::creating(function ($model) {
            if (! $model->getKey()) {
                $model->setAttribute($model->getKeyName(), Ids::new(static::ID_PREFIX));
            }
        });
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }
}
