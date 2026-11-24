<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Creates (or refreshes) Laravel's own Redis ACL user "laravel" on a Redis that has no ACL file,
 * logging in with the host's Redis password. Run it as the first release command on hosts like
 * NanoDeploi, so the web app never waits for the engine to create its login.
 * The rules come from resources/redis/laravel.acl, generated from engine permissions.py (a test
 * keeps the two in sync). Does nothing when PS_REDIS_PASSWORD_LARAVEL is not set.
 */
class RedisUser extends Command
{
    protected $signature = 'pipstream:redis-user';

    protected $description = 'Create the "laravel" Redis ACL user (for Redis without an ACL file)';

    public function handle(): int
    {
        $password = (string) config('pipstream.redis_laravel_password');
        if ($password === '') {
            $this->info('PS_REDIS_PASSWORD_LARAVEL is not set: Laravel uses the host\'s Redis login, nothing to do.');

            return self::SUCCESS;
        }
        $rules = self::rules($password);
        $a = config('pipstream.redis_admin');

        try {
            $redis = new \Redis;
            $redis->connect((string) $a['host'], (int) $a['port'], 5.0);
            if (filled($a['password'])) {
                $redis->auth(filled($a['username']) ? [$a['username'], $a['password']] : $a['password']);
            }
            $redis->rawCommand('ACL', 'SETUSER', 'laravel', 'reset', ...$rules);
            $redis->close();
        } catch (\Throwable $e) {
            $this->error('Could not create the Redis user "laravel": '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info('Redis user "laravel" is ready.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    public static function rules(string $password): array
    {
        $template = trim((string) file_get_contents(resource_path('redis/laravel.acl')));

        return preg_split('/\s+/', str_replace('__PASSWORD__', $password, $template));
    }
}
