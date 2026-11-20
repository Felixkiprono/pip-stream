<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

class Position extends Model
{
    use HasPrefixedId;

    public const ID_PREFIX = 'pos';

    protected $table = 'positions';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['volume' => 'float', 'open_price' => 'float', 'sl' => 'float', 'tp' => 'float',
        'close_price' => 'float', 'profit' => 'float', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }
}
