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

    public function test_pairing_with_the_code_on_the_chart(): void
    {
        $ea = fn (array $body) => $this->call('POST', '/ea/v1/pair', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
        $first = $ea(['platform' => 'mt5', 'login' => '87654321', 'server' => 'Exness-MT5Trial8', 'company' => 'Exness'])->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/^PAIR\|[A-Za-z0-9]{40}\|\d{6}\n$/', $first);
        [, $token, $code] = explode('|', trim($first));
        $this->assertSame("WAIT|{$code}\n", $ea(['token' => $token])->getContent());

        $u = $this->customer();
        $this->actingAs($u)->postJson('/api/v1/brokers/pair', ['code' => '000000'])->assertStatus(422)->assertJsonValidationErrors('code');
        $res = $this->postJson('/api/v1/brokers/pair', ['code' => $code])->assertOk();
        $res->assertJsonPath('broker.connector', 'ea_bridge')->assertJsonPath('broker.label', 'MetaTrader 5 · Exness 87654321');
        $this->actingAs($this->customer())->postJson('/api/v1/brokers/pair', ['code' => $code])->assertStatus(422);  // one use only
        $this->actingAs($u);

        // the EA collects its key once, and it works
        $got = trim($ea(['token' => $token])->getContent());
        $this->assertStringStartsWith('KEY|psk_', $got);
        $key = substr($got, 4);
        $this->assertSame(hash('sha256', $key), BrokerAccount::find($res->json('broker.id'))->symbol_map['_ea_key_hash']);
        $this->call('POST', '/ea/v1/sync', [], [], [], ['HTTP_X-PipStream-Key' => $key])->assertOk();
        $this->assertStringStartsWith('PAIR|', $ea(['token' => $token])->getContent());  // token spent
    }

    public function test_connection_test_is_handed_to_the_engine_and_read_back(): void
    {
        [$u, $broker] = $this->addEa();
        $id = $broker['id'];
        $tid = $this->actingAs($u)->postJson("/api/v1/brokers/{$id}/test")->assertOk()->json('test_id');
        $ev = collect($this->streamEvents('config.changed'))->last();
        $this->assertSame(['entity' => 'broker_test', 'entity_id' => $id, 'test_id' => $tid, 'order' => false], $ev['payload']);
        $this->getJson("/api/v1/brokers/{$id}/test/{$tid}")->assertJsonPath('test.state', 'queued');

        $this->redis()->set(config('pipstream.stream_prefix').":acct:{$id}:test:{$tid}", json_encode(['state' => 'done', 'ok' => true, 'steps' => [['name' => 'Terminal answered', 'ok' => true]]]));
        $this->getJson("/api/v1/brokers/{$id}/test/{$tid}")->assertJsonPath('test.ok', true);

        $this->postJson("/api/v1/brokers/{$id}/test", ['order' => true])->assertOk();
        $this->postJson("/api/v1/brokers/{$id}/test", ['order' => true])->assertStatus(429);  // one test order a minute
        $this->actingAs($this->customer())->postJson("/api/v1/brokers/{$id}/test")->assertNotFound();  // not yours
    }

    public function test_ea_download_carries_the_address_it_was_downloaded_from(): void
    {
        $res = $this->get('http://127.0.0.1:8000/ea/PipStream.mq5')->assertOk();
        $this->assertStringContainsString('#define PS_DEFAULT_URL "http://127.0.0.1:8000"', $res->getContent());
        $this->assertStringContainsString('attachment; filename="PipStream.mq5"', $res->headers->get('Content-Disposition'));

        config(['pipstream.ea_url' => 'https://pips.stream/']);
        $this->assertStringContainsString('#define PS_DEFAULT_URL "https://pips.stream"', $this->get('/ea/PipStream.mq4')->getContent());
        $this->get('/ea/PipStream.ex9')->assertDontSee('PS_DEFAULT_URL');
    }

    public function test_pair_status_says_when_a_terminal_from_here_has_reached_us(): void
    {
        $this->actingAs($this->customer());
        $this->getJson('/api/v1/brokers/pair/status')->assertOk()->assertJsonPath('seen', null);
        $this->call('POST', '/ea/v1/pair', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['platform' => 'mt5', 'company' => 'Exness']))->assertOk();
        $this->getJson('/api/v1/brokers/pair/status')->assertJsonPath('seen.company', 'Exness')->assertJsonPath('seen.platform', 'mt5');
    }

    public function test_code_from_the_chart_links_an_existing_row_and_retires_its_old_key(): void
    {
        [$u, $broker, $oldKey] = $this->addEa();
        $first = $this->call('POST', '/ea/v1/pair', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['platform' => 'mt5', 'login' => '71908446', 'company' => 'Exness']))->getContent();
        [, $token, $code] = explode('|', trim($first));

        $this->actingAs($this->customer())->postJson('/api/v1/brokers/pair', ['code' => $code, 'broker_id' => $broker['id']])->assertNotFound();
        $res = $this->actingAs($u)->postJson('/api/v1/brokers/pair', ['code' => $code, 'broker_id' => $broker['id']])->assertOk();
        $res->assertJsonPath('broker.id', $broker['id'])->assertJsonPath('broker.label', 'MetaTrader 5 · Exness 71908446');
        $this->assertSame(1, BrokerAccount::where('user_id', $u->id)->where('connector', 'ea_bridge')->count());

        $key = substr(trim($this->call('POST', '/ea/v1/pair', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['token' => $token]))->getContent()), 4);
        $this->call('POST', '/ea/v1/sync', [], [], [], ['HTTP_X-PipStream-Key' => $key])->assertOk();
        $this->call('POST', '/ea/v1/sync', [], [], [], ['HTTP_X-PipStream-Key' => $oldKey])->assertStatus(401);
    }

    public function test_one_line_setup_links_the_terminal_without_a_code(): void
    {
        $u = $this->customer();
        $res = $this->actingAs($u)->postJson('http://127.0.0.1:8000/api/v1/brokers/installer', ['platform' => 'mt5'])->assertOk();
        $this->assertMatchesRegularExpression('#^curl -fsSL http://127\.0\.0\.1:8000/ea/install/pst_[A-Za-z0-9]{40}\.sh \| bash$#', $res->json('mac'));
        $this->assertMatchesRegularExpression('#^irm http://127\.0\.0\.1:8000/ea/install/pst_[A-Za-z0-9]{40}\.ps1 \| iex$#', $res->json('windows'));
        $id = $res->json('broker.id');
        preg_match('#(pst_[A-Za-z0-9]{40})#', $res->json('mac'), $m);
        $token = $m[1];

        $sh = $this->get("http://127.0.0.1:8000/ea/install/{$token}.sh")->assertOk()->getContent();
        $this->assertStringContainsString('URL="http://127.0.0.1:8000"', $sh);
        $this->assertStringContainsString("TOKEN=\"{$token}\"", $sh);
        $this->assertStringContainsString('MT="5"', $sh);
        $this->assertStringContainsString("\$Token = '{$token}'", $this->get("/ea/install/{$token}.ps1")->getContent());

        // the EA trades the token for its key on first contact, once
        $ea = fn () => $this->call('POST', '/ea/v1/pair', [], [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['install' => $token, 'platform' => 'mt5', 'login' => '71908446', 'company' => 'Exness']))->getContent();
        $got = trim($ea());
        $this->assertStringStartsWith('KEY|psk_', $got);
        $this->call('POST', '/ea/v1/sync', [], [], [], ['HTTP_X-PipStream-Key' => substr($got, 4)])->assertOk();
        $this->assertSame('MetaTrader 5 · Exness 71908446', BrokerAccount::find($id)->label);
        $this->assertStringStartsWith('PAIR|', $ea());  // used up: falls back to a code
        $this->get("/ea/install/{$token}.sh")->assertNotFound();

        // the same row can get a fresh command
        $this->actingAs($u)->postJson('/api/v1/brokers/installer', ['platform' => 'mt5', 'broker_id' => $id])->assertJsonPath('broker.id', $id);
        $this->get('/ea/PipStream.ex5')->assertNotFound();  // no ready-built EA in the repo yet: 404, never the app's HTML
    }

    public function test_a_busy_ea_does_not_use_up_the_setup_and_login_limits(): void
    {
        [$u, $b, $key] = $this->addEa();
        for ($i = 0; $i < 35; $i++) {
            $this->call('POST', '/ea/v1/sync', [], [], [], ['HTTP_X-PipStream-Key' => $key])->assertOk();
        }
        $cmd = $this->actingAs($u)->postJson('/api/v1/brokers/installer', ['platform' => 'mt5', 'broker_id' => $b['id']])->assertOk()->json('mac');
        preg_match('#(pst_[A-Za-z0-9]{40})#', $cmd, $m);
        $this->get("/ea/install/{$m[1]}.sh")->assertOk();
    }

    public function test_terminal_prices_let_practice_accounts_fill_market_signals(): void
    {
        [, , $key] = $this->addEa();
        $this->call('POST', '/ea/v1/sync', [], [], [], ['HTTP_X-PipStream-Key' => $key, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['state' => ['balance' => 1000, 'positions' => [], 'quotes' => ['EURUSDm' => [1.1191, 1.1193], 'XAUUSD' => [2440.1, 2440.5], 'BAD KEY' => [1, 2]]]]))->assertOk();
        $p = config('pipstream.stream_prefix');
        $this->assertSame('1.1192', $this->redis()->get("{$p}:quote:EURUSDm"));
        $this->assertSame('1.1192', $this->redis()->get("{$p}:quote:EURUSD"));
        $this->assertSame('2440.3', $this->redis()->get("{$p}:quote:XAUUSD"));
        $this->assertFalse($this->redis()->get("{$p}:quote:BAD KEY"));
    }

    public function test_status_says_when_metatrader_is_not_logged_in_to_the_broker(): void
    {
        [$u, $b, $key] = $this->addEa();
        $this->call('POST', '/ea/v1/sync', [], [], [], ['HTTP_X-PipStream-Key' => $key, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['state' => ['balance' => 0, 'positions' => [], 'trade_allowed' => true, 'broker_connected' => false]]))->assertOk();
        $this->actingAs($u)->getJson("/api/v1/brokers/{$b['id']}")->assertJsonPath('broker.status.state', 'error')
            ->assertJsonPath('broker.status.detail', "MetaTrader isn't connected to your broker. In MetaTrader: File → Login to Trade Account.");
        $this->call('POST', '/ea/v1/sync', [], [], [], ['HTTP_X-PipStream-Key' => $key, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['state' => ['positions' => [], 'broker_connected' => false, 'quotes' => ['GBPJPY' => [190.1, 190.2]]]]))->assertOk();
        $this->assertFalse($this->redis()->get(config('pipstream.stream_prefix').':quote:GBPJPY'));  // frozen prices aren't used
    }
}
