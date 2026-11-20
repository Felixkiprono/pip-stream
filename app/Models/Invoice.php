<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasPrefixedId;

    public const ID_PREFIX = 'inv';

    protected $table = 'invoices';

    protected $guarded = [];

    public const UPDATED_AT = null;
}
