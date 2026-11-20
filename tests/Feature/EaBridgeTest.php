<?php

namespace Tests\Feature;

use App\Models\BrokerAccount;
use App\Pipstream\Bus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EaBridgeTest extends TestCase
{
    use RefreshDatabase;

    private function redis()
    {
        return app(Bus::class)->redis()->client();
    }

    private function addEa(): array
    {
        $u = $this->customer();
        $res = $this->actingAs($u)->postJson('/api/v1/brokers', ['connector' => 'ea_bridge', 'platform' => 'mt4'])->assertOk();

        return [$u, $res->json('broker'), $res->json('ea_key')];
    }

    public function test_ea_account_gets_a_key_shown_once_and_stored_only_as_a_hash(): void
    {
        [$u, $broker, $key] = $this->addEa();
        $this->assertMatchesRegularExpression('/^psk_[A-Za-z0-9]{40}$/', $key);
        $this->assertSame('MetaTrader 4 (EA)', $broker['label']);
        $this->assertSame('connecting', $broker['status']['state']);
        $b = BrokerAccount::find($broker['id']);
        $this->assertNull($b->credentials_ciphertext);
        $this->assertSame(hash('sha256', $key), $b->symbol_map['_ea_key_hash']);
        $this->assertStringNotContainsString($key, $this->getJson('/api/v1/brokers')->getContent());
    }

    public function test_sync_needs_a_valid_key(): void
    {
        $this->postJson('/ea/v1/sync', [])->assertStatus(401);
        $this->postJson('/ea/v1/sync', [], ['X-PipStream-Key' => 'psk_'.str_repeat('x', 40)])->assertStatus(401);
    }

    public function test_sync_hands_out_commands_and_stores_answers_and_state(): void
    {
        [$u, $broker, $key] = $this->addEa();
        $id = $broker['id'];
        $p = config('pipstream.stream_prefix');
        $future = (int) (microtime(true) * 1000) + 5000;
        // what the executor queues (the web app itself is not allowed to, see the Redis ACL)
        $this->redis()->xAdd("{$p}:acct:{$id}:ea:cmd", '*', ['c' => json_encode(['id' => 'old1', 'action' => 'open', 'expires_ms' => 1])]);
        $this->redis()->xAdd("{$p}:acct:{$id}:ea:cmd", '*', ['c' => json_encode(['id' => 'cmdA1', 'action' => 'open',
            'symbol' => 'XAUUSDm', 'side' => 'buy', 'order_type' => 'market', 'volume' => 0.1, 'price' => null,
            'sl' => 2340, 'tp' => 2360, 'ticket' => null, 'comment' => 'ps7t1', 'expires_ms' => $future])]);

        $state = ['balance' => 10000, 'equity' => 10002.5, 'currency' => 'USD', 'login' => '51234567',
            'server' => 'Exness-MT4Trial7', 'company' => 'Exness', 'trade_allowed' => true, 'positions' => []];
        $res = $this->call('POST', '/ea/v1/sync', [], [], [], ['HTTP_X-PipStream-Key' => $key, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['results' => [], 'state' => $state, 'specs' => ['XAUUSDm' => ['point' => 0.001, 'tick_size' => 0.001, 'tick_value' => 0.1]]]));
        $res->assertOk();
        $lines = explode("\n", trim($res->getContent()));
        $this->assertStringStartsWith('OK|1000|', $lines[0]);
        $this->assertSame(['C|cmdA1|open|XAUUSDm|buy|market|0.1||2340|2360||ps7t1'], array_slice($lines, 1));  // expired one skipped

        // the EA answers; the command is not handed out again
        $res = $this->call('POST', '/ea/v1/sync', [], [], [], ['HTTP_X-PipStream-Key' => $key, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['results' => [['id' => 'cmdA1', 'ok' => true, 'ticket' => '5001', 'price' => 2349.4, 'volume' => 0.1, 'retcode' => 0, 'error' => '']]]));
        $this->assertSame(1, count(explode("\n", trim($res->getContent()))));
        $r = json_decode($this->redis()->get("{$p}:eares:{$id}:r:cmdA1"), true);
        $this->assertTrue($r['ok']);
        $this->assertSame('5001', $r['ticket']);
        $this->assertSame(0.1, json_decode($this->redis()->get("{$p}:eares:{$id}:specs"), true)['XAUUSDm']['tick_value']);

        // the app shows the terminal as connected, with the account it reported
        $this->actingAs($u)->getJson("/api/v1/brokers/{$id}")
            ->assertJsonPath('broker.status.state', 'connected')
            ->assertJsonPath('broker.status.account.login', '51234567')
            ->assertJsonPath('broker.status.account.company', 'Exness');
    }

    public function test_new_key_replaces_the_old_one(): void
    {
        [$u, $broker, $key] = $this->addEa();
        $this->call('POST', '/ea/v1/sync', [], [], [], ['HTTP_X-PipStream-Key' => $key])->assertOk();
        $new = $this->actingAs($u)->postJson("/api/v1/brokers/{$broker['id']}/ea-key")->assertOk()->json('ea_key');
        $this->assertNotSame($key, $new);
        $this->call('POST', '/ea/v1/sync', [], [], [], ['HTTP_X-PipStream-Key' => $key])->assertStatus(401);
        $this->call('POST', '/ea/v1/sync', [], [], [], ['HTTP_X-PipStream-Key' => $new])->assertOk();
    }
}
