<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Installation-wide settings managed from the admin console, stored encrypted with APP_KEY
 * (e.g. the Resend API key). Same shape as NanoDeploi's PlatformSetting.
 */
class PlatformSetting extends Model
{
    protected $table = 'platform_settings';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'encrypted:array'];
    }

    /** @return array<string, mixed>|null */
    public static function read(string $key): ?array
    {
        return Cache::remember('platform-setting:'.$key, 300, function () use ($key): ?array {
            try {
                // Before the migration has run there is simply nothing stored.
                if (! Schema::hasTable('platform_settings')) {
                    return null;
                }

                return static::query()->find($key)?->value;
            } catch (Throwable) {
                return null; // no database yet (build steps, artisan optimize)
            }
        });
    }

    /** @param  array<string, mixed>|null  $value  null removes the setting */
    public static function write(string $key, ?array $value): void
    {
        if ($value === null) {
            static::query()->whereKey($key)->delete();
        } else {
            static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Cache::forget('platform-setting:'.$key);
    }
}
