<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

class BrokerAccount extends Model
{
    use HasPrefixedId;

    public const ID_PREFIX = 'ba';

    protected $table = 'broker_accounts';

    protected $guarded = [];

    protected $hidden = ['credentials_ciphertext'];

    protected $casts = ['symbol_map' => 'array', 'enabled' => 'boolean'];

    public function hasCredentials(): bool
    {
        return $this->credentials_ciphertext !== null;
    }
}
