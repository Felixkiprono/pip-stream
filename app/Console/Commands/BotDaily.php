<?php

namespace App\Console\Commands;

use App\Models\BillingSubscription;
use App\Models\User;
use App\Notifications\TradeAlert;
use App\Pipstream\Accounts;
use App\Pipstream\Bot\BotApi;
use App\Pipstream\Bot\BotScreens;
use App\Pipstream\Bot\BotSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Once a day in the evening (routes/console.php, PS_BOT_DAILY_AT): a short summary of the day
 * in each linked bot chat that had any activity, and plan reminders (3 days left, 1 day left,
 * ended) in the app's alerts and the bot.
 */
class BotDaily extends Command
{
    protected $signature = 'pipstream:bot-daily {--only= : summary|plans}';

    protected $description = 'Daily bot summary and plan reminders';

    public function handle(): int
    {
        $only = $this->option('only');
        if ($only !== 'summary') {
            $this->info('plan reminders: '.$this->plans());
        }
        if ($only !== 'plans' && BotSettings::token()) {
            $this->info('summaries: '.$this->summaries());
        }

        return self::SUCCESS;
    }

    private function plans(): int
    {
        $n = 0;
        $subs = BillingSubscription::whereNotNull('current_period_end')->where('plan_id', '<>', 'lifetime')
            ->whereIn('status', ['trialing', 'active', 'past_due'])
            ->whereBetween('current_period_end', [now()->subDay(), now()->addDays(3)->endOfDay()])->with('plan')->get();
        foreach ($subs as $s) {
            $u = User::find($s->user_id);
            if (! $u || $u->founding_number) {
                continue;
            }
            $days = (int) ceil(now()->diffInHours($s->current_period_end, false) / 24);
            $stage = $days <= 0 ? 'ended' : ($days <= 1 ? '1' : ($days <= 3 ? '3' : null));
            if (! $stage || ! Cache::add("planreminder:{$s->user_id}:{$s->current_period_end->timestamp}:{$stage}", 1, now()->addDays(10))) {
                continue;
            }
            $name = $s->plan?->name ?? 'plan';
            $trial = $s->status === 'trialing';
            Accounts::safely(fn () => $u->notify($stage === 'ended'
                ? new TradeAlert('plan_ended', ($trial ? 'Your free trial' : "Your {$name} plan").' has ended',
                    'New signals are not copied until you renew. Your channels and settings are kept.')
                : new TradeAlert('plan_ending', ($trial ? 'Your free trial' : "Your {$name} plan").' ends '.($stage === '1' ? 'tomorrow' : 'in 3 days'),
                    'Renew in a minute (card or M-Pesa) so copying keeps going without a break.')));
            $n++;
        }

        return $n;
    }

    private function summaries(): int
    {
        $api = BotApi::make();
        $from = now()->startOfDay();
        $chats = DB::table('bot_chats')->whereNotNull('user_id')->whereNull('blocked_at')->where('daily_summary', true)->get();
        $n = 0;
        foreach ($chats as $c) {
            if (! Cache::add("botdaily:{$c->user_id}:".$from->toDateString(), 1, now()->addDays(2))) {
                continue;
            }
            $u = $c->user_id;
            $opened = DB::table('positions')->where('user_id', $u)->where('opened_at', '>=', $from)->count();
            $closed = DB::table('positions')->where('user_id', $u)->where('status', 'closed')->where('closed_at', '>=', $from)
                ->selectRaw('count(*) n, coalesce(sum(profit),0) pnl, count(*) filter (where profit > 0) wins')->first();
            $failed = DB::table('orders')->where('user_id', $u)->where('status', 'failed')->where('created_at', '>=', $from)->count();
            if (! $opened && ! $closed->n && ! $failed) {
                continue; // a quiet day: no message
            }
            $signals = DB::table('signals')->join('subscriptions', 'subscriptions.channel_id', '=', 'signals.channel_id')
                ->where('subscriptions.user_id', $u)->where('signals.status', 'parsed')->where('signals.created_at', '>=', $from)->count();
            $best = DB::table('positions')->join('channels', 'channels.id', '=', 'positions.channel_id')
                ->where('positions.user_id', $u)->where('positions.status', 'closed')->where('positions.closed_at', '>=', $from)
                ->groupBy('channels.title')->selectRaw('channels.title, sum(profit) p')->orderByDesc('p')->first();
            $open = DB::table('positions')->where('user_id', $u)->where('status', 'open')->count();
            $lines = ['🌙 Your day on PipStream', '',
                "Signals from your channels: {$signals}",
                "Trades opened: {$opened}".($failed ? " · couldn't copy: {$failed}" : ''),
                "Trades closed: {$closed->n}".($closed->n ? ' · result '.BotScreens::money((float) $closed->pnl)." ({$closed->wins} won)" : ''),
            ];
            if ($best && (float) $best->p > 0) {
                $lines[] = "Best channel: {$best->title} (".BotScreens::money((float) $best->p).')';
            }
            $lines[] = "Still open: {$open}";
            if ($api->send($c->chat_id, implode("\n", $lines), [['📈 My trades' => 'm:trades', '💰 Profit' => 'm:pnl'], ['🔕 Stop daily summary' => 'st:daily:0']]) !== null) {
                $n++;
            }
        }

        return $n;
    }
}
