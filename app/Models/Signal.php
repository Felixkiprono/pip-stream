<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

class Signal extends Model
{
    use HasPrefixedId;

    public const ID_PREFIX = 'sig';

    protected $table = 'signals';

    protected $guarded = [];

    public const UPDATED_AT = null;

    protected $casts = ['payload' => 'array', 'confidence' => 'float'];

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }
}
