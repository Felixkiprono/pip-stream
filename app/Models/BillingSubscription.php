<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

class BillingSubscription extends Model
{
    use HasPrefixedId;

    public const ID_PREFIX = 'bsub';

    protected $table = 'billing_subscriptions';

    protected $guarded = [];

    public const CREATED_AT = null;

    protected $casts = ['current_period_end' => 'datetime'];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function isEntitled(): bool
    {
        return in_array($this->status, ['trialing', 'active'], true)
            && ($this->current_period_end === null || $this->current_period_end->isFuture());
    }
}
