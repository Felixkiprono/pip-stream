<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasPrefixedId;

    public const ID_PREFIX = 'plan';

    protected $table = 'plans';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['allow_live' => 'boolean'];
}
