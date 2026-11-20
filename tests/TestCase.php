<?php

namespace Tests;

use App\Models\User;
use App\Pipstream\Accounts;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;

abstract class TestCase extends BaseTestCase
{
    /** A sealed-box keypair standing in for the engine's executor key. */
    protected string $executorSecret;

    protected function setUp(): void
    {
        parent::setUp();
        $kp = sodium_crypto_box_keypair();
        $this->executorSecret = $kp;
        config(['pipstream.executor_public_key' => base64_encode(sodium_crypto_box_publickey($kp))]);
        // isolate streams per test run
        config(['pipstream.stream_prefix' => 'pstest'.getmypid()]);
        $r = Redis::connection('streams')->client();
        foreach ($r->keys('pstest'.getmypid().':*') as $k) {
            $r->del($k);
        }
    }

    protected function customer(array $attrs = []): User
    {
        $u = User::create($attrs + ['name' => 'Wanjiru', 'email' => 'w'.uniqid().'@example.com', 'password' => 'secret-pass-1']);
        app(Accounts::class)->onboard($u);

        return $u;
    }

    protected function streamEvents(string $topic): array
    {
        $r = Redis::connection('streams')->client();
        $out = [];
        foreach ($r->xRange(config('pipstream.stream_prefix').':stream:'.$topic, '-', '+') ?: [] as $f) {
            $out[] = json_decode($f['e'], true);
        }

        return $out;
    }
}
