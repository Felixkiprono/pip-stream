<?php

namespace App\Jobs;

use App\Pipstream\Bot\BotApi;
use App\Pipstream\Bot\BotUpdates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Sends one Admin broadcast through @pip_stream_bot, about 20 messages a second (Telegram allows 30). */
class SendBroadcast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    /** @param array<int, array{chat_id:int, user_id:?string}> $to */
    public function __construct(public int $broadcastId, public array $to) {}

    public function handle(): void
    {
        $b = DB::table('bot_broadcasts')->where('id', $this->broadcastId)->first();
        if (! $b || $b->finished_at) {
            return;
        }
        $api = BotApi::make();
        $sent = $failed = 0;
        foreach ($this->to as $i => $r) {
            $ok = false;
            for ($try = 0; $try < 3 && ! $ok; $try++) {
                try {
                    $ok = $api->send($r['chat_id'], $b->text) !== null;
                    break;
                } catch (Throwable) {
                    usleep(1_500_000); // rate limited or Telegram busy: wait and retry
                }
            }
            $ok ? $sent++ : $failed++;
            if ($ok) {
                BotUpdates::store($r['chat_id'], $r['user_id'], 'out', 'broadcast', $b->text, $b->admin_id, $b->id);
            }
            if ($i % 20 === 19) {
                DB::table('bot_broadcasts')->where('id', $b->id)->update(['sent' => $sent, 'failed' => $failed]);
            }
            usleep(50_000);
        }
        DB::table('bot_broadcasts')->where('id', $b->id)->update(['sent' => $sent, 'failed' => $failed, 'finished_at' => now()]);
    }
}
