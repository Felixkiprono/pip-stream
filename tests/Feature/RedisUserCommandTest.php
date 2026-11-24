<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/** `pipstream:redis-user` on a Redis that only has --requirepass (NanoDeploi's managed Redis). */
class RedisUserCommandTest extends TestCase
{
    public function test_it_creates_the_laravel_user_with_the_engine_rules(): void
    {
        $bin = trim((string) shell_exec('command -v redis-server'));
        if ($bin === '' || ! class_exists(\Redis::class)) {
            $this->markTestSkipped('redis-server or phpredis not available');
        }
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $proc = new Process([$bin, '--port', (string) $port, '--requirepass', 'site-pw', '--save', '', '--appendonly', 'no']);
        $proc->start();
        try {
            for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
                usleep(100000);
            }
            config(['pipstream.redis_laravel_password' => 'own-pw',
                'pipstream.redis_admin' => ['host' => '127.0.0.1', 'port' => $port, 'username' => null, 'password' => 'site-pw']]);

            $this->artisan('pipstream:redis-user')->assertSuccessful();
            $this->artisan('pipstream:redis-user')->assertSuccessful(); // idempotent

            $r = new \Redis;
            $r->connect('127.0.0.1', $port);
            $this->assertTrue($r->auth(['laravel', 'own-pw']));
            $this->assertTrue($r->set('laravel:cache:k', 'v'));       // its own keys
            $this->assertNotFalse($r->xAdd('ps:stream:config.changed', '*', ['x' => '1']));
            try {
                $ok = $r->xAdd('ps:stream:trade.intent', '*', ['x' => '1']); // never a trade
            } catch (\RedisException $e) {
                $ok = false;
            }
            $this->assertFalse($ok);
        } finally {
            $proc->stop();
        }
    }

    public function test_it_does_nothing_without_a_laravel_password(): void
    {
        config(['pipstream.redis_laravel_password' => null]);
        $this->artisan('pipstream:redis-user')->assertSuccessful();
    }
}
