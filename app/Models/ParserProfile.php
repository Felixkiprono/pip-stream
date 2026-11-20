<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

class ParserProfile extends Model
{
    use HasPrefixedId;

    public const ID_PREFIX = 'pp';

    protected $table = 'parser_profiles';

    protected $guarded = [];

    public const CREATED_AT = null;

    protected $casts = ['config' => 'array', 'min_confidence' => 'float'];
}
