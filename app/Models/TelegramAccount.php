<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

class TelegramAccount extends Model
{
    use HasPrefixedId;

    public const ID_PREFIX = 'tga';

    protected $table = 'telegram_accounts';

    protected $guarded = [];

    protected $hidden = ['session_ciphertext'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isLinked(): bool
    {
        return $this->status === 'active' && $this->session_ciphertext !== null;
    }
}
