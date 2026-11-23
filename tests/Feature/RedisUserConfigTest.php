<?php

namespace Tests\Feature;

use Tests\TestCase;

/** On hosts that own REDIS_PASSWORD/REDIS_URL (NanoDeploi), Laravel logs in as its own "laravel" user. */
class RedisUserConfigTest extends TestCase
{
    private function redisConfig(array $env): array
    {
        $keys = ['PS_REDIS_PASSWORD_LARAVEL', 'REDIS_URL', 'REDIS_USERNAME', 'REDIS_PASSWORD'];
        $saved = [];
        foreach ($keys as $k) {
            $saved[$k] = $_ENV[$k] ?? null;
            unset($_ENV[$k], $_SERVER[$k]);
            putenv($k);
        }
        foreach ($env as $k => $v) {
            $_ENV[$k] = $_SERVER[$k] = $v;
            putenv("$k=$v");
        }
        try {
            return (require config_path('database.php'))['redis'];
        } finally {
            foreach ($keys as $k) {
                unset($_ENV[$k], $_SERVER[$k]);
                putenv($k);
                if ($saved[$k] !== null) {
                    $_ENV[$k] = $_SERVER[$k] = $saved[$k];
                    putenv("$k={$saved[$k]}");
                }
            }
        }
    }

    public function test_host_redis_credentials_are_used_without_a_laravel_password(): void
    {
        $r = $this->redisConfig(['REDIS_URL' => 'redis://:site@redis:6379/0', 'REDIS_PASSWORD' => 'site', 'REDIS_USERNAME' => 'laravel']);
        $this->assertSame('redis://:site@redis:6379/0', $r['default']['url']);
        $this->assertSame('laravel', $r['default']['username']);
        $this->assertSame('site', $r['default']['password']);
    }

    public function test_laravel_acl_user_wins_over_the_hosts_redis_url(): void
    {
        $r = $this->redisConfig(['REDIS_URL' => 'redis://:site@redis:6379/0', 'REDIS_PASSWORD' => 'site', 'PS_REDIS_PASSWORD_LARAVEL' => 'own']);
        foreach (['default', 'cache', 'streams'] as $c) {
            $this->assertNull($r[$c]['url'], $c);
            $this->assertSame('laravel', $r[$c]['username'], $c);
            $this->assertSame('own', $r[$c]['password'], $c);
        }
    }
}
