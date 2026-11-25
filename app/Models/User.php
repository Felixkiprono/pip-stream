<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedId;
use App\Notifications\ResetPassword;
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
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'kill_switch' => 'boolean', 'founding_number' => 'integer', 'last_seen_at' => 'datetime'];
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'superadmin'], true);
    }

    /** Platform settings (email keys) and other admins' roles. */
    public function isSuperadmin(): bool
    {
        return $this->role === 'superadmin';
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPassword($token));
    }

    /** RESEND_TEST_TO / Admin → Email "send all mail to": testing before the domain is verified (as in NanoDeploi). */
    public function routeNotificationForMail(): string
    {
        return config('mail.resend_test_to') ?: $this->email;
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
