<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPref extends Model
{
    protected $table = 'notification_prefs';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'user_id';

    protected $guarded = [];
}
