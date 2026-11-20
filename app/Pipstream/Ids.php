<?php

namespace App\Pipstream;

use Illuminate\Support\Str;

/** Same id shape as the engine (pipstream.common.ids): prefix_ULID, e.g. usr_01J... */
final class Ids
{
    public static function new(string $prefix): string
    {
        return $prefix.'_'.strtoupper((string) Str::ulid());
    }
}
