<?php

namespace Tests\Feature;

use App\Models\TelegramAccount;
use App\Pipstream\TelegramLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TelegramFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_step_publishes_request_and_returns_pending_until_engine_answers(): void
    {
        $u = $this->customer();
        $this->actingAs($u)->postJson('/api/v1/telegram/phone', ['phone' => '0712345678'])->assertStatus(422);
        $res = $this->postJson('/api/v1/telegram/phone', ['phone' => '+254712345678'])->assertOk()->assertJsonPath('status', 'pending');
        $rid = $res->json('request_id');

        $req = $this->streamEvents('tg.login.request')[0];
        $this->assertSame('laravel', $req['producer']);
        $this->assertSame(['request_id', 'telegram_account_id', 'user_id', 'step', 'phone'], array_keys($req['payload']));
        $this->assertSame('send_code', $req['payload']['step']);

        // the engine answers on tg.login.status; pipstream:consume stores it
        TelegramLink::remember(['request_id' => $rid, 'telegram_account_id' => $req['payload']['telegram_account_id'],
            'user_id' => $u->id, 'status' => 'code_sent', 'phone_hint' => '678']);
        $this->getJson("/api/v1/telegram/requests/{$rid}")->assertJsonPath('status', 'code_sent');

        $other = $this->customer();
        $this->actingAs($other)->getJson("/api/v1/telegram/requests/{$rid}")->assertNotFound();
    }

    public function test_follow_channels_from_linked_account_reads_each_channel_once(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        $accA = TelegramAccount::create(['user_id' => $a->id, 'status' => 'active', 'session_ciphertext' => 'sb1:x']);
        $accB = TelegramAccount::create(['user_id' => $b->id, 'status' => 'active', 'session_ciphertext' => 'sb1:y']);
        TelegramLink::remember(['request_id' => 'r1', 'telegram_account_id' => $accA->id, 'user_id' => $a->id,
            'status' => 'channels', 'channels' => [['tg_chat_id' => -1001, 'title' => 'Gold Room', 'username' => 'goldroom']]]);
        $this->actingAs($a)->getJson('/api/v1/telegram/channels')->assertJsonPath('channels.0.followed', false);

        $chan = ['tg_chat_id' => -1001, 'title' => 'Gold Room', 'username' => 'goldroom'];
        $this->postJson('/api/v1/channels/follow', ['channels' => [$chan]])->assertOk();
        $this->getJson('/api/v1/telegram/channels')->assertJsonPath('channels.0.followed', true);
        $this->getJson('/api/v1/channels')->assertJsonPath('channels.0.title', 'Gold Room')
            ->assertJsonPath('channels.0.rule.style', 'balanced')->assertJsonPath('channels.0.rule.mode', 'paper');
        $this->actingAs($b)->postJson('/api/v1/channels/follow', ['channels' => [$chan]])->assertOk();

        $roles = DB::table('channel_readers')->pluck('role', 'telegram_account_id');
        $this->assertSame('active', $roles[$accA->id]);
        $this->assertSame('standby', $roles[$accB->id]);   // read once, fanned out
        $this->assertSame(1, DB::table('channels')->count());

        // A leaves: B's account is promoted so the channel keeps being read
        $this->actingAs($a)->deleteJson('/api/v1/channels/'.DB::table('channels')->value('id'))->assertOk();
        $this->assertSame('active', DB::table('channel_readers')->where('telegram_account_id', $accB->id)->value('role'));
        $this->assertCount(3, $this->streamEvents('config.changed'));   // follow, follow, unfollow;
    }

    public function test_cannot_follow_without_linked_telegram(): void
    {
        $this->actingAs($this->customer())->postJson('/api/v1/channels/follow',
            ['channels' => [['tg_chat_id' => -5, 'title' => 'x']]])->assertNotFound();
    }
}
