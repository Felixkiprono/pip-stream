<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

class Channel extends Model
{
    use HasPrefixedId;

    public const ID_PREFIX = 'ch';

    protected $table = 'channels';

    protected $guarded = [];

    protected $casts = ['active' => 'boolean', 'tg_chat_id' => 'integer'];

    public function profile()
    {
        return $this->belongsTo(ParserProfile::class, 'parser_profile_id');
    }

    public function subscribers()
    {
        return $this->belongsToMany(User::class, 'subscriptions', 'channel_id', 'user_id');
    }
}
