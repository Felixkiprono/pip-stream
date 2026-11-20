<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedId;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasPrefixedId, Notifiable;

    public const ID_PREFIX = 'usr';

    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'kill_switch' => 'boolean'];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function telegramAccounts(): HasMany
    {
        return $this->hasMany(TelegramAccount::class);
    }

    public function brokerAccounts(): HasMany
    {
        return $this->hasMany(BrokerAccount::class);
    }

    public function copyRules(): HasMany
    {
        return $this->hasMany(CopyRule::class);
    }

    public function billing(): HasOne
    {
        return $this->hasOne(BillingSubscription::class);
    }

    public function notificationPrefs(): HasMany
    {
        return $this->hasMany(NotificationPref::class);
    }
}
