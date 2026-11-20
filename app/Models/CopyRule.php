<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

class CopyRule extends Model
{
    use HasPrefixedId;

    public const ID_PREFIX = 'rule';

    protected $table = 'copy_rules';

    protected $guarded = [];

    protected $casts = ['filters' => 'array', 'limits' => 'array', 'enabled' => 'boolean', 'fixed_lots' => 'float',
        'risk_pct' => 'float', 'multiplier' => 'float', 'stake' => 'float', 'max_lot' => 'float'];

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    public function brokerAccount()
    {
        return $this->belongsTo(BrokerAccount::class);
    }
}
