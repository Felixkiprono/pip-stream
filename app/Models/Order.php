<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasPrefixedId;

    public const ID_PREFIX = 'ord';

    protected $table = 'orders';

    protected $guarded = [];

    public const UPDATED_AT = null;

    protected $casts = ['volume' => 'float', 'price' => 'float'];
}
