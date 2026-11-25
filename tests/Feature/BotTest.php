<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\CopyRule;
use App\Models\Position;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Notifications\BotLinkCode;
use App\Pipstream\Bot\Bot;
use App\Pipstream\Bot\BotSettings;
use App\Pipstream\Bus;
use App\Pipstream\EventHandler;
use App\Pipstream\Watchdog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** @pip_stream_bot: setup, linking, buttons, commands, confirmations, support, broadcasts, ops alerts. */
class BotTest extends TestCase
{
    use RefreshDatabase;

    private int $update = 1;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pipstream.bot_token' => '123456:'.str_repeat('A', 35)]);
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 1, 'username' => 'pip_stream_bot', 'first_name' => 'PipStream']]),
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '', 'pending_update_count' => 0]]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]]),
            '*/parse-preview' => Http::response(['status' => 'parsed', 'signal' => ['kind' => 'open', 'symbol' => 'XAUUSD', 'side' => 'buy',
                'entry' => ['type' => 'range', 'from' => 2348, 'to' => 2350], 'sl' => 2340, 'tps' => [2360, 2375], 'confidence' => 0.93]]),
        ]);
    }

    // ---------------------------------------------------------------- helpers
    /** What the bot sent: [method, payload] for every Bot API call after the given index. */
    private function calls(string $method = 'sendMessage'): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'api.telegram.org') && str_ends_with($r->url(), '/'.$method))
            ->map(fn (Request $r) => $r->data())->values()->all();
    }

    private function lastCall(): array
    {
        $c = $this->calls();

        return $c[count($c) - 1];
    }

    private function lastText(string $method = 'sendMessage'): string
    {
        $c = $this->calls($method);

        return (string) (end($c)['text'] ?? '');
    }

    private function buttons(array $call): array
    {
        return collect($call['reply_markup']['inline_keyboard'] ?? [])->flatten(1)->pluck('callback_data', 'text')->filter()->all();
    }

    private function hook(array $update)
    {
        return $this->postJson('/webhooks/telegram/test-bot-secret', ['update_id' => $this->update++] + $update)->assertOk();
    }

    private function say(int $chat, string $text, array $extra = [])
    {
        return $this->hook(['message' => ['message_id' => 5, 'text' => $text, 'chat' => ['id' => $chat, 'type' => 'private'],
            'from' => ['id' => $chat, 'first_name' => 'Wanjiru']] + $extra]);
    }

    private function tap(int $chat, string $data)
    {
        return $this->hook(['callback_query' => ['id' => 'cb'.$this->update, 'data' => $data, 'from' => ['id' => $chat, 'first_name' => 'Wanjiru'],
            'message' => ['message_id' => 9, 'chat' => ['id' => $chat, 'type' => 'private']]]]);
    }

    private function linked(int $chat = 4242, array $attrs = []): User
    {
        $u = $this->customer($attrs);
        Bot::link($chat, $u, 'test');

        return $u;
    }

    private function channelFor(User $u, string $title = 'Gold Signals'): Channel
    {
        $acc = TelegramAccount::create(['user_id' => $u->id, 'status' => 'active', 'session_ciphertext' => 'sb1:x']);

        return app(\App\Pipstream\Accounts::class)->follow($u, $acc, -100123, $title, 'goldsig');
    }

    // ---------------------------------------------------------------- setup
    public function test_superadmin_saves_the_token_and_the_webhook_is_set_with_a_secret(): void
    {
        $boss = User::forceCreate(['name' => 'Felix', 'email' => 'boss@example.com', 'password' => 'secret-pass-1', 'role' => 'superadmin']);
        $admin = User::forceCreate(['name' => 'Helper', 'email' => 'help@example.com', 'password' => 'secret-pass-1', 'role' => 'admin']);
        $this->actingAs($admin)->getJson('/api/v1/admin/bot')->assertForbidden();
        $this->actingAs($boss)->putJson('/api/v1/admin/bot', ['token' => 'not-a-token'])->assertUnprocessable();

        $token = '987654321:'.str_repeat('b', 35);
        $r = $this->actingAs($boss)->putJson('/api/v1/admin/bot', ['token' => $token])->assertOk();
        $r->assertJsonPath('source', 'admin')->assertJsonPath('username', 'pip_stream_bot')->assertJsonPath('token_hint', '••••bbbb');
        $this->assertStringNotContainsString($token, $r->getContent());

        $hook = $this->calls('setWebhook')[0];
        $secret = BotSettings::secret();
        $this->assertSame(40, strlen($secret));
        $this->assertSame($secret, $hook['secret_token']);
        $this->assertStringEndsWith('/webhooks/telegram/'.$secret, $hook['url']);
        $this->assertNotEmpty($this->calls('setMyCommands')[0]['commands']);
        $this->assertSame($token, BotSettings::token());

        // the env secret no longer works; the stored one does, and a wrong header is refused
        $this->postJson('/webhooks/telegram/test-bot-secret', ['update_id' => 1])->assertNotFound();
        $this->postJson("/webhooks/telegram/{$secret}", ['update_id' => 2], ['X-Telegram-Bot-Api-Secret-Token' => 'nope'])->assertNotFound();
        $this->postJson("/webhooks/telegram/{$secret}", ['update_id' => 3], ['X-Telegram-Bot-Api-Secret-Token' => $secret])->assertOk();

        $this->actingAs($boss)->getJson('/api/v1/admin/bot')->assertOk()->assertJsonPath('check.ok', false)
            ->assertJsonPath('me_linked', false);
    }

    // ---------------------------------------------------------------- linking
    public function test_unlinked_chat_gets_a_welcome_and_can_link_with_an_email_code(): void
    {
        Notification::fake();
        $u = $this->customer(['email' => 'wanjiru@example.com']);
        $this->say(555, '/start');
        $this->assertStringContainsString('connect your PipStream account', $this->lastText());
        $this->say(555, '/status');                              // no account yet: welcome again, nothing leaks
        $this->assertStringContainsString('Connect my account', json_encode($this->calls()[1]['reply_markup'], JSON_UNESCAPED_UNICODE));

        $this->tap(555, 'link');
        $this->say(555, 'not-an-email');
        $this->assertStringContainsString('doesn\'t look like an email', $this->lastText());
        $this->say(555, 'Wanjiru@Example.com');
        $code = null;
        Notification::assertSentTo($u, BotLinkCode::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });
        $this->say(555, '000000');
        $this->assertStringContainsString('isn\'t right', $this->lastText());
        $this->assertNull(Bot::chatOf($u));
        $this->say(555, substr($code, 0, 3).' '.substr($code, 3));
        $this->assertSame(555, Bot::chatOf($u));
        $this->assertStringContainsString('You\'re connected', $this->lastText());
        $this->assertArrayHasKey('keyboard', $this->lastCall()['reply_markup']);  // the big menu buttons
    }

    public function test_unknown_email_gets_the_same_answer_and_no_mail(): void
    {
        Notification::fake();
        $this->tap(556, 'link');
        $this->say(556, 'nobody@example.com');
        $this->assertStringContainsString('If nobody@example.com has a PipStream account', $this->lastText());
        Notification::assertNothingSent();
    }

    // ---------------------------------------------------------------- screens and buttons
    public function test_status_pause_resume_and_the_menu_buttons(): void
    {
        $u = $this->linked();
        $this->say(4242, '📊 Status');
        $this->assertStringContainsString('Copying: ✅ On', $this->lastText());
        $this->assertStringContainsString('Practice account (practice) ✅', $this->lastText());

        $this->tap(4242, 'p:1');
        $this->assertTrue($u->fresh()->kill_switch);
        $this->assertSame('1', app(Bus::class)->redis()->client()->get(config('pipstream.stream_prefix').":killswitch:user:{$u->id}"));
        $this->assertStringContainsString('Copying: ⏸ Paused by you', $this->lastText('editMessageText'));
        $this->assertStringContainsString('▶️ Resume copying', json_encode($this->calls()[count($this->calls()) - 1]['reply_markup'], JSON_UNESCAPED_UNICODE));

        $this->say(4242, '/resume');
        $this->assertFalse($u->fresh()->kill_switch);
        $this->assertStringContainsString('Copying is back on', $this->lastText());

        foreach (['/trades' => 'No open trades', '/pnl' => 'Your results', '/plan' => 'Your plan', '/help' => 'Need help?',
            '/settings' => 'Trade alerts: ✅ On', '/channels' => 'don\'t follow any channels'] as $cmd => $want) {
            $this->say(4242, $cmd);
            $this->assertStringContainsString($want, $this->lastText(), $cmd);
        }
    }

    public function test_close_all_asks_first_then_asks_the_engine(): void
    {
        $u = $this->linked();
        $ch = $this->channelFor($u);
        Position::create(['user_id' => $u->id, 'broker_account_id' => $u->brokerAccounts()->first()->id, 'channel_id' => $ch->id,
            'source_message_id' => 1, 'signal_id' => 'sig_1', 'ticket' => '11', 'symbol' => 'XAUUSD', 'side' => 'buy', 'volume' => 0.1,
            'open_price' => 2350, 'status' => 'open', 'profit' => 4.2]);
        $this->say(4242, '📈 My trades');
        $this->assertStringContainsString('BUY 0.1 XAUUSD @ 2350', $this->lastText());
        $this->assertContains('ca:ask', $this->buttons($this->lastCall()));

        $this->tap(4242, 'ca:ask');
        $this->assertStringContainsString('Close all 1 open trade now?', $this->lastText('editMessageText'));
        $this->assertCount(0, $this->streamEvents('user.command'));
        $this->tap(4242, 'ca:yes');
        $this->tap(4242, 'ca:yes'); // a double tap sends one command
        $ev = $this->streamEvents('user.command');
        $this->assertCount(1, $ev);
        $this->assertSame(['close_all', $u->id], [$ev[0]['payload']['action'], $ev[0]['payload']['user_id']]);
    }

    public function test_channels_pause_restyle_ask_first_and_unfollow(): void
    {
        $u = $this->linked();
        $ch = $this->channelFor($u);
        $this->say(4242, '/channels');
        $this->assertStringContainsString('Gold Signals · ✅ copying · Balanced', $this->lastText());
        $this->tap(4242, "ch:{$ch->id}");
        $this->assertStringContainsString('Style: Balanced', $this->lastText('editMessageText'));

        $this->tap(4242, "ce:{$ch->id}:0");
        $this->assertFalse(CopyRule::where('channel_id', $ch->id)->first()->enabled);
        $this->tap(4242, "cs:{$ch->id}:careful");
        $this->tap(4242, "cq:{$ch->id}:1");
        $r = CopyRule::where('channel_id', $ch->id)->first();
        $this->assertSame(0.5, $r->risk_pct);
        $this->assertTrue($r->limits['confirm_first']);
        $this->tap(4242, "cs:{$ch->id}:bold");   // a style change keeps "ask me first"
        $this->assertTrue(CopyRule::where('channel_id', $ch->id)->first()->limits['confirm_first']);
        $this->assertNotEmpty($this->streamEvents('config.changed'));

        // the web app keeps it too when only the style changes
        $this->actingAs($u)->putJson("/api/v1/channels/{$ch->id}/rule", ['style' => 'balanced'])->assertOk()
            ->assertJsonPath('rule.limits.confirm_first', true);

        $this->tap(4242, "cu:{$ch->id}");
        $this->assertStringContainsString('Stop following Gold Signals?', $this->lastText('editMessageText'));
        $this->tap(4242, "cux:{$ch->id}");
        $this->assertDatabaseMissing('subscriptions', ['user_id' => $u->id, 'channel_id' => $ch->id]);
    }

    public function test_follow_a_channel_from_the_bot(): void
    {
        $u = $this->linked();
        $acc = TelegramAccount::create(['user_id' => $u->id, 'status' => 'active', 'session_ciphertext' => 'sb1:x']);
        Cache::put("tgchannels:{$acc->id}", [['tg_chat_id' => -100777, 'title' => 'Forex Kings', 'username' => 'fxk']], 600);
        $this->say(4242, '/follow');
        $this->assertContains('fl:-100777', $this->buttons($this->lastCall()));
        $this->tap(4242, 'fl:-100777');
        $ch = Channel::where('tg_chat_id', -100777)->first();
        $this->assertDatabaseHas('subscriptions', ['user_id' => $u->id, 'channel_id' => $ch->id]);
        $this->assertStringContainsString('Now following Forex Kings', $this->lastText('editMessageText'));
    }

    public function test_following_without_a_telegram_reader_points_to_the_app(): void
    {
        $this->linked();
        $this->say(4242, '/follow');
        $this->assertStringContainsString('one-time setup in the app', $this->lastText());
    }

    // ---------------------------------------------------------------- ask before each trade
    public function test_a_held_signal_asks_with_buttons_and_a_yes_goes_to_the_engine(): void
    {
        $u = $this->linked();
        $ch = $this->channelFor($u);
        DB::table('signals')->insert(['id' => 'sig_9', 'channel_id' => $ch->id, 'message_id' => 3, 'status' => 'parsed', 'kind' => 'open',
            'symbol' => 'XAUUSD', 'side' => 'buy', 'payload' => json_encode(['kind' => 'open', 'symbol' => 'XAUUSD', 'side' => 'buy', 'sl' => 2340, 'tps' => [2360]])]);
        $ev = ['topic' => 'risk.limit_hit', 'payload' => ['user_id' => $u->id, 'broker_account_id' => $u->brokerAccounts()->first()->id,
            'copy_rule_id' => 'r', 'signal_id' => 'sig_9', 'limit' => 'awaiting_confirm', 'detail' => 'pnd_ABC']];
        app(EventHandler::class)($ev);
        app(EventHandler::class)($ev); // replayed: asked once
        $asks = array_values(array_filter($this->calls(), fn ($c) => str_contains($c['text'], 'New signal from Gold Signals')));
        $this->assertCount(1, $asks);
        $this->assertStringContainsString('BUY XAUUSD · at market price · SL 2340 · TP 2360', $asks[0]['text']);
        $this->assertSame(['✅ Copy it' => 'cf:y:pnd_ABC', '✖ Skip' => 'cf:n:pnd_ABC'], $this->buttons($asks[0]));
        $this->assertSame(0, $u->notifications()->count());  // no "skipped" alert: it is waiting

        $this->tap(4242, 'cf:y:pnd_ABC');
        $cmd = $this->streamEvents('user.command');
        $this->assertSame(['approve_signal', 'pnd_ABC'], [$cmd[0]['payload']['action'], $cmd[0]['payload']['pending_id']]);
        $this->assertStringContainsString('Copying BUY XAUUSD', $this->lastText('editMessageText'));
        $this->tap(4242, 'cf:y:pnd_ABC');
        $this->assertCount(1, $this->streamEvents('user.command'));
        $this->assertStringContainsString('expired', $this->lastText('editMessageText'));
    }

    public function test_a_held_signal_without_a_bot_chat_is_explained_in_the_app(): void
    {
        $u = $this->customer();
        app(EventHandler::class)(['topic' => 'risk.limit_hit', 'payload' => ['user_id' => $u->id, 'broker_account_id' => 'b',
            'copy_rule_id' => 'r', 'signal_id' => 'nope', 'limit' => 'awaiting_confirm', 'detail' => 'pnd_X']]);
        $this->assertSame('Signal waiting for your OK', $u->notifications()->first()->data['title']);
    }

    public function test_asking_first_needs_the_bot(): void
    {
        $u = $this->customer();
        $ch = $this->channelFor($u);
        $this->actingAs($u)->putJson("/api/v1/channels/{$ch->id}/rule", ['style' => 'balanced', 'confirm_first' => true])->assertUnprocessable();
    }

    // ---------------------------------------------------------------- alerts
    public function test_trade_alerts_have_buttons_and_can_be_muted_but_health_alerts_cannot(): void
    {
        $u = $this->linked();
        $ch = $this->channelFor($u);
        $trade = ['topic' => 'trade.executed', 'payload' => ['user_id' => $u->id, 'channel_id' => $ch->id, 'symbol' => 'XAUUSD', 'side' => 'buy',
            'status' => 'executed', 'action' => 'open', 'volume' => 0.1, 'price' => 2350, 'sl' => 2340, 'tp' => 2360, 'mode' => 'paper']];
        app(EventHandler::class)($trade);
        $this->assertStringContainsString('✅ Bought 0.1 XAUUSD (practice)', $this->lastText());
        $this->assertSame(['📈 My trades' => 'm:trades', '💰 Profit' => 'm:pnl'], $this->buttons($this->lastCall()));

        $this->tap(4242, 'st:trade:0');
        $n = count($this->calls());
        app(EventHandler::class)($trade);
        $this->assertCount($n, $this->calls());   // muted
        $u->notify(new \App\Notifications\TradeAlert('ea_offline', 'MetaTrader is offline', 'Check it.'));
        $this->assertStringContainsString('🔌 MetaTrader is offline', $this->lastText());
    }

    public function test_sign_in_notice_and_it_wasnt_me_signs_out_everywhere(): void
    {
        $this->withoutDefer();
        $u = $this->linked(4242, ['email' => 'w@example.com']);
        $this->postJson('/api/v1/auth/login', ['email' => 'w@example.com', 'password' => 'secret-pass-1'],
            ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)'])->assertOk();
        $this->assertStringContainsString('New sign-in to PipStream', $this->lastText());
        $this->assertStringContainsString('iPhone', $this->lastText());
        $before = $u->fresh()->remember_token;
        $this->tap(4242, 'sec:lock');
        $this->assertNotSame($before, $u->fresh()->remember_token);
        $this->assertStringContainsString('signed out on every device', $this->lastText('editMessageText'));
    }

    public function test_real_money_account_can_be_confirmed_from_the_bot(): void
    {
        $u = $this->linked();
        $b = \App\Models\BrokerAccount::create(['user_id' => $u->id, 'connector' => 'ea_bridge', 'label' => 'Exness', 'symbol_map' => [], 'enabled' => true]);
        app(Bus::class)->redis()->client()->set(config('pipstream.stream_prefix').":eares:{$b->id}:state", json_encode(['demo' => false, 'login' => '5001', 'company' => 'Exness']));
        $this->tap(4242, "lv:y:{$b->id}");
        $this->assertSame('5001', $b->fresh()->symbol_map['_live_ok_login']);
        $this->assertStringContainsString('login 5001', $this->lastText('editMessageText'));
    }

    // ---------------------------------------------------------------- forward to test, support
    public function test_forwarded_post_shows_how_it_reads_and_can_be_reported(): void
    {
        $u = $this->linked();
        $this->say(4242, 'XAUUSD BUY 2348-2350 SL 2340 TP 2360 / 2375',
            ['forward_origin' => ['type' => 'channel', 'chat' => ['id' => -100555, 'title' => 'Gold VIP', 'type' => 'channel']]]);
        $t = $this->lastText();
        $this->assertStringContainsString('from Gold VIP', $t);
        $this->assertStringContainsString('BUY XAUUSD · entry 2348–2350 · SL 2340 · TP 2360 / 2375', $t);
        $this->assertStringContainsString('93%', $t);
        $this->tap(4242, 'rp');
        $this->assertDatabaseHas('bot_messages', ['chat_id' => 4242, 'user_id' => $u->id, 'direction' => 'in', 'kind' => 'support']);
    }

    public function test_support_messages_reach_admin_and_replies_go_back(): void
    {
        $u = $this->linked();
        $this->say(4242, 'Hello, my trades are not copying');
        $this->say(4242, 'Any help?');
        $this->assertCount(1, array_filter($this->calls(), fn ($c) => str_contains($c['text'], 'Got it!')));  // acknowledged once

        $admin = User::forceCreate(['name' => 'Helper', 'email' => 'help@example.com', 'password' => 'secret-pass-1', 'role' => 'admin']);
        $this->actingAs($u)->getJson('/api/v1/admin/support')->assertForbidden();
        $inbox = $this->actingAs($admin)->getJson('/api/v1/admin/support')->assertOk();
        $inbox->assertJsonPath('unread', 2)->assertJsonPath('conversations.0.chat_id', 4242)->assertJsonPath('conversations.0.email', $u->email);
        $this->getJson('/api/v1/admin/support/4242')->assertOk()->assertJsonCount(2, 'messages');
        $this->getJson('/api/v1/admin/support')->assertJsonPath('unread', 0);
        $this->postJson('/api/v1/admin/support/4242', ['text' => 'We are on it!'])->assertOk();
        $this->assertStringContainsString("PipStream team:\nWe are on it!", $this->lastText());
        $this->postJson("/api/v1/admin/users/{$u->id}/message", ['text' => 'Fixed now.'])->assertOk();
        $this->assertDatabaseHas('bot_messages', ['chat_id' => 4242, 'direction' => 'out', 'kind' => 'direct', 'admin_id' => $admin->id]);
    }

    // ---------------------------------------------------------------- broadcasts, ops, daily
    public function test_broadcast_to_a_segment(): void
    {
        $boss = User::forceCreate(['name' => 'Felix', 'email' => 'boss@example.com', 'password' => 'secret-pass-1', 'role' => 'superadmin']);
        $a = $this->linked(1001);
        $this->linked(1002);
        $a->forceFill(['founding_number' => 1])->save();
        $seg = collect($this->actingAs($boss)->getJson('/api/v1/admin/bot/segments')->assertOk()->json('segments'))->keyBy('id');
        $this->assertSame([2, 1], [$seg['linked']['count'], $seg['founding']['count']]);

        $this->postJson('/api/v1/admin/bot/broadcast', ['segment' => 'founding', 'text' => 'Thanks for being a founding member!'])->assertOk();
        $sent = array_values(array_filter($this->calls(), fn ($c) => $c['text'] === 'Thanks for being a founding member!'));
        $this->assertSame([1001], array_column($sent, 'chat_id'));
        $this->assertDatabaseHas('bot_broadcasts', ['segment' => 'founding', 'total' => 1, 'sent' => 1]);
    }

    public function test_watchdog_alerts_reach_operators_on_telegram(): void
    {
        Notification::fake();
        $admin = User::forceCreate(['name' => 'Felix', 'email' => 'boss@example.com', 'password' => 'secret-pass-1', 'role' => 'superadmin']);
        Bot::link(9001, $admin, 'test');
        $this->customer(); // customers never get ops alerts
        Cache::put('watchdog:started', microtime(true) - 3600, now()->addDay());
        app(Watchdog::class)->run();   // nothing is running in tests: workers are down
        $ops = array_filter($this->calls(), fn ($c) => $c['chat_id'] === 9001 && str_starts_with($c['text'], '🚨'));
        $this->assertNotEmpty($ops);

        BotSettings::save(['ops_alerts' => false]);
        DB::table('system_alerts')->delete();
        $n = count($this->calls());
        app(Watchdog::class)->run();
        $this->assertCount($n, $this->calls());
    }

    public function test_daily_summary_and_plan_reminders(): void
    {
        $u = $this->linked();
        $ch = $this->channelFor($u);
        Position::create(['user_id' => $u->id, 'broker_account_id' => $u->brokerAccounts()->first()->id, 'channel_id' => $ch->id,
            'source_message_id' => 1, 'signal_id' => 'sig_1', 'ticket' => '12', 'symbol' => 'EURUSD', 'side' => 'sell', 'volume' => 0.1,
            'open_price' => 1.1, 'status' => 'closed', 'profit' => 12.5, 'closed_at' => now()]);
        $u->billing()->update(['current_period_end' => now()->addDays(2)]);
        $quiet = $this->linked(4343); // nothing happened today: no summary

        $this->artisan('pipstream:bot-daily')->assertSuccessful();
        $summaries = array_values(array_filter($this->calls(), fn ($c) => str_contains($c['text'], 'Your day on PipStream')));
        $this->assertCount(1, $summaries);
        $this->assertSame(4242, $summaries[0]['chat_id']);
        $this->assertStringContainsString('result +12.50 (1 won)', $summaries[0]['text']);
        $this->assertStringContainsString('Best channel: Gold Signals (+12.50)', $summaries[0]['text']);
        $this->assertSame('plan_ending', $u->notifications()->first()->data['kind']);

        $this->artisan('pipstream:bot-daily')->assertSuccessful();   // once a day
        $this->assertCount(1, array_filter($this->calls(), fn ($c) => str_contains($c['text'], 'Your day on PipStream')));
        $this->assertSame(1, $u->notifications()->count());
    }

    public function test_customer_settings_show_the_bot_chat_and_send_a_test(): void
    {
        $u = $this->linked();
        $this->actingAs($u)->getJson('/api/v1/settings')->assertJsonPath('alerts.telegram', true)
            ->assertJsonPath('bot.trade_alerts', true)->assertJsonPath('bot_username', 'pip_stream_bot');
        $this->putJson('/api/v1/settings', ['bot_daily_summary' => false])->assertJsonPath('bot.daily_summary', false);
        $this->postJson('/api/v1/settings/telegram-alerts/test')->assertOk();
        $this->assertStringContainsString('This is a test from PipStream', $this->lastText());
        $this->putJson('/api/v1/settings', ['telegram_alerts' => false])->assertJsonPath('alerts.telegram', false);
        $this->assertNull(Bot::chatOf($u));
    }
}
