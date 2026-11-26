<?php

namespace App\Pipstream;

use App\Models\User;
use App\Notifications\SystemAlert;
use App\Notifications\TradeAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Runs every minute from the scheduler (`pipstream:watchdog`). Looks at every moving part and
 * opens an incident in system_alerts when one stops, emails the operators once, and emails
 * again when it recovers:
 *
 *   worker:events     the event consumer (pipstream:consume) stopped beating
 *   worker:queue      queued jobs stopped running (a heartbeat job is queued every minute)
 *   engine:<w>        an engine worker (intake, risk, executor) stopped beating
 *   errors:orders     many orders failing at once
 *   errors:app        the web app logging many exceptions
 *   errors:jobs       queued jobs failing
 *   ea:<broker id>    a customer's MetaTrader stopped checking in (the customer is told too)
 *
 * Grace periods are generous on purpose: the queue worker restarts itself every hour
 * (--max-time=3600) and a deploy restarts everything, neither of which should page anyone.
 *
 * No spam: one "down" and one "back" message per incident. Something that goes down again within
 * FLAP_WINDOW_S of recovering (a laptop that sleeps, a flaky connection) opens a quiet incident:
 * nobody is told unless it stays down for FLAP_WINDOW_S, and its recovery is quiet too.
 * Closing an incident is a single conditional UPDATE, so the minute check here and the instant
 * check when an EA calls in again (eaCheckedIn) can never both send "back online".
 */
class Watchdog
{
    public const WORKER_GRACE_S = 120;     // engine workers and the consumer beat every few seconds
    public const QUEUE_GRACE_S = 300;      // heartbeat job queued every minute; worker restarts hourly
    public const EA_GRACE_S = 180;         // EA checks in every second while MetaTrader is open
    public const WARMUP_S = 300;           // after the watchdog (re)starts, give everything time to come up
    public const FLAP_WINDOW_S = 900;      // down again this soon after recovering: say nothing unless it stays down this long

    public function __construct(private Bus $bus) {}

    /** @return array<int, array{key:string, ok:bool, title:string, detail:string, severity?:string, user_id?:string}> */
    public function checks(): array
    {
        $now = microtime(true);
        $out = [];
        $age = fn ($beat) => $beat ? $now - (float) $beat : null;
        $ago = fn (?float $s) => $s === null ? 'never' : (round($s) < 120 ? round($s).' s ago' : round($s / 60).' min ago');

        $c = $age(Cache::get('pipstream:consumer:beat'));
        $out[] = ['key' => 'worker:events', 'ok' => $c !== null && $c < self::WORKER_GRACE_S,
            'title' => 'Event consumer stopped', 'detail' => "pipstream:consume last ran {$ago($c)}. Trade alerts and Telegram linking wait until it is back (compose service \"events\")."];
        $q = $age(Cache::get('pipstream:queue:beat'));
        $out[] = ['key' => 'worker:queue', 'ok' => $q !== null && $q < self::QUEUE_GRACE_S,
            'title' => 'Queue worker stopped', 'detail' => "No queued job has run since {$ago($q)}. Customer alerts (email, Telegram bot) are not going out (compose service \"queue\")."];

        $r = null;
        try {
            $r = $this->bus->redis()->client();
        } catch (Throwable) {
        }
        $p = config('pipstream.stream_prefix');
        foreach (['intake' => 'reads Telegram and parses posts', 'risk' => 'turns signals into orders', 'executor' => 'places orders at brokers'] as $w => $does) {
            $a = null;
            try {
                $a = $age($r?->get("{$p}:health:{$w}"));
            } catch (Throwable) {
            }
            $out[] = ['key' => "engine:{$w}", 'ok' => $a !== null && $a < self::WORKER_GRACE_S,
                'title' => 'Engine '.$w.' worker down', 'detail' => "The {$w} worker ({$does}) last reported {$ago($a)}. No signals are copied while it is down (compose service \"engine\")."];
        }

        // ------------------------------------------------------------ error rates
        $o = DB::selectOne("select count(*) filter (where status = 'failed') as failed, count(*) as total from orders "
            ."where created_at > now() - interval '15 minutes' and coalesce(error, '') not in ('kill_switch')");
        $failed = (int) $o->failed;
        $total = (int) $o->total;
        $out[] = ['key' => 'errors:orders', 'ok' => ! ($failed >= 5 && $failed * 2 >= $total), 'severity' => 'warn',
            'title' => 'Many orders failing', 'detail' => "{$failed} of {$total} orders failed in the last 15 minutes. Admin → Copy activity shows the errors."];
        $ex = self::exceptionsSince(10);
        $out[] = ['key' => 'errors:app', 'ok' => $ex < 20, 'severity' => 'warn',
            'title' => 'Web app errors spiking', 'detail' => "{$ex} errors logged by the web app in the last 10 minutes. See the php container logs."];
        try {
            $fj = DB::table('failed_jobs')->where('failed_at', '>', now()->subMinutes(15))->count();
        } catch (Throwable) {
            $fj = 0;
        }
        $out[] = ['key' => 'errors:jobs', 'ok' => $fj < 5, 'severity' => 'warn',
            'title' => 'Queued jobs failing', 'detail' => "{$fj} queued jobs failed in the last 15 minutes (php artisan queue:failed)."];

        // ------------------------------------------------------------ customers' MetaTrader (EA heartbeat)
        $eas = DB::select("select b.id, b.user_id, b.label from broker_accounts b where b.enabled and b.connector = 'ea_bridge' "
            ."and exists (select 1 from copy_rules r where r.broker_account_id = b.id and r.enabled and r.mode = 'live')");
        foreach ($eas as $b) {
            $seen = null;
            try {
                $seen = $r?->get("{$p}:eares:{$b->id}:seen");
            } catch (Throwable) {
            }
            $a = $age($seen);
            // never connected yet: nothing went offline (the app's setup screens cover that)
            if ($a === null && ! Cache::has("watchdog:ea-seen:{$b->id}")) {
                continue;
            }
            if ($a !== null) {
                Cache::put("watchdog:ea-seen:{$b->id}", 1, now()->addDays(30));
            }
            $out[] = ['key' => "ea:{$b->id}", 'ok' => $a !== null && $a < self::EA_GRACE_S, 'severity' => 'warn', 'user_id' => $b->user_id,
                'title' => "MetaTrader offline: {$b->label}", 'detail' => "The PipStream EA last checked in {$ago($a)}. Signals for this account fail until MetaTrader is open with the EA on a chart."];
        }

        // ------------------------------------------------------------ customers' Telegram reader (the session that reads channels)
        $tgs = DB::select('select distinct on (t.user_id) t.id, t.user_id, t.status, (t.session_ciphertext is not null) as has_session '
            .'from telegram_accounts t where exists (select 1 from subscriptions s where s.user_id = t.user_id) '
            .'order by t.user_id, t.created_at desc');
        foreach ($tgs as $t) {
            $ok = $t->status === 'active' && $t->has_session;
            if ($ok) {
                Cache::put("watchdog:tg-seen:{$t->id}", 1, now()->addDays(60));
            } elseif (! Cache::has("watchdog:tg-seen:{$t->id}")) {
                continue; // never connected: the setup screens cover that
            }
            $out[] = ['key' => "tg:{$t->id}", 'ok' => $ok, 'severity' => 'warn', 'user_id' => $t->user_id,
                'title' => 'Customer Telegram disconnected', 'detail' => "A customer's Telegram session ({$t->status}) can't read their channels, so their signals are not copied until they reconnect."];
        }

        return $out;
    }

    /** One pass: open incidents for new failures, close recovered ones, notify. Returns what changed. */
    public function run(): array
    {
        Cache::add('watchdog:started', microtime(true), now()->addDays(60));
        $started = (float) Cache::get('watchdog:started');
        Cache::put('watchdog:last_run', microtime(true), now()->addDay());
        $warm = microtime(true) - $started >= self::WARMUP_S;
        $open = DB::table('system_alerts')->whereNull('resolved_at')->get()->keyBy('key');
        $changed = ['opened' => [], 'resolved' => []];

        $checks = $this->checks();
        foreach ($checks as $c) {
            $row = $open->get($c['key']);
            if (! $c['ok'] && ! $row && ($warm || str_starts_with($c['key'], 'errors:'))) {
                $id = DB::table('system_alerts')->insertGetId(['key' => $c['key'], 'severity' => $c['severity'] ?? 'error',
                    'title' => $c['title'], 'detail' => $c['detail'], 'user_id' => $c['user_id'] ?? null, 'started_at' => now()]);
                if (! $this->flapping($c['key'], $id)) {
                    $this->notify($c, false);
                    DB::table('system_alerts')->where('id', $id)->update(['notified_at' => now()]);
                }
                $changed['opened'][] = $c['key'];
            } elseif (! $c['ok'] && $row) {
                DB::table('system_alerts')->where('id', $row->id)->update(['detail' => $c['detail']]);
                // a quiet (flapping) incident that has now lasted long enough: tell people after all
                if (! $row->notified_at && time() - strtotime($row->started_at) >= self::FLAP_WINDOW_S
                    && DB::table('system_alerts')->where('id', $row->id)->whereNull('notified_at')->update(['notified_at' => now()])) {
                    $this->notify($c, false);
                }
            } elseif ($c['ok'] && $row) {
                if ($this->close($row, $c)) {
                    $changed['resolved'][] = $c['key'];
                }
            }
        }
        // a broker account that was removed or stopped copying live: nothing to watch any more
        DB::table('system_alerts')->whereNull('resolved_at')->where(fn ($w) => $w->where('key', 'like', 'ea:%')->orWhere('key', 'like', 'tg:%'))
            ->whereNotIn('key', array_column($checks, 'key'))->update(['resolved_at' => now()]);

        return $changed;
    }

    /** Went down again soon after an earlier incident on the same thing recovered. */
    private function flapping(string $key, int $exceptId): bool
    {
        return DB::table('system_alerts')->where('key', $key)->where('id', '!=', $exceptId)
            ->where('resolved_at', '>', now()->subSeconds(self::FLAP_WINDOW_S))->exists();
    }

    /** Close an open incident and send "back to normal" once, only if "down" was sent. False if someone else closed it first. */
    private function close(object $row, array $c): bool
    {
        if (! DB::table('system_alerts')->where('id', $row->id)->whereNull('resolved_at')->update(['resolved_at' => now()])) {
            return false;
        }
        if ($row->notified_at) {
            $mins = max(1, (int) round((time() - strtotime($row->started_at)) / 60));
            $this->notify(['key' => $row->key, 'title' => $row->title, 'user_id' => $row->user_id] + $c, true, $mins);
        }

        return true;
    }

    /**
     * Called from the EA's sync when a terminal calls in after being quiet for longer than the grace
     * period: closes its open "MetaTrader offline" incident at once, so the customer hears "back
     * online" within seconds instead of at the next minute check. Cheap when nothing is open.
     */
    public function eaCheckedIn(string $brokerId): bool
    {
        Cache::put("watchdog:ea-seen:{$brokerId}", 1, now()->addDays(30));
        $row = DB::table('system_alerts')->where('key', "ea:{$brokerId}")->whereNull('resolved_at')->first();

        return $row ? $this->close($row, ['key' => $row->key, 'ok' => true, 'title' => $row->title, 'detail' => '']) : false;
    }

    private function notify(array $c, bool $recovered, int $mins = 0): void
    {
        $title = $c['title'];
        $detail = $recovered ? "{$c['title']}: back to normal after about {$mins} min." : $c['detail'];
        if (str_starts_with($c['key'], 'ea:') && ($u = User::find($c['user_id'] ?? ''))) {
            Accounts::safely(fn () => $u->notify($recovered
                ? new TradeAlert('ea_online', 'MetaTrader is back online', 'PipStream is copying to it again.', ['broker_account_id' => substr($c['key'], 3)])
                : new TradeAlert('ea_offline', 'MetaTrader is offline', 'PipStream has not heard from your MetaTrader for a few minutes, so signals can\'t be copied to it. Check that the computer is on, MetaTrader is open and the PipStream EA is on a chart with Algo Trading on.', ['broker_account_id' => substr($c['key'], 3)])));
        }
        if (str_starts_with($c['key'], 'tg:') && ($u = User::find($c['user_id'] ?? ''))) {
            Accounts::safely(fn () => $u->notify($recovered
                ? new TradeAlert('tg_reconnected', 'Telegram is connected again', 'PipStream is reading your channels again.')
                : new TradeAlert('tg_disconnected', 'Your Telegram is disconnected', 'PipStream can\'t read your channels any more (Telegram ended the session, for example after you logged out of other devices). Signals are not copied until you reconnect. It takes a minute.')));
        }
        // operators on Telegram too (admins who linked @pip_stream_bot), when turned on in Admin → Telegram bot
        Bot\Bot::quietly(fn () => Bot\Bot::toOperators(($recovered ? "✅ Recovered: {$title}\n" : "🚨 {$title}\n").$detail,
            [['Open System' => PublicUrl::to('/admin/system')]]));
        foreach (self::operators() as $email) {
            try {
                MailSettings::apply();
                Notification::route('mail', $email)->notifyNow(new SystemAlert($title, $detail, $recovered));
            } catch (Throwable $e) {
                Log::error('watchdog alert email failed', ['key' => $c['key'], 'error' => mb_substr($e->getMessage(), 0, 300)]);
            }
        }
        Log::warning($recovered ? 'watchdog: recovered' : 'watchdog: alert', ['key' => $c['key'], 'detail' => $detail]);
    }

    /** Who gets operator alerts: PS_ALERT_EMAILS, else the superadmin. */
    public static function operators(): array
    {
        $list = array_filter(array_map('trim', explode(',', (string) config('pipstream.alert_emails'))));

        return array_values(array_unique($list ?: array_filter([(string) config('pipstream.superadmin_email')])));
    }

    // ---------------------------------------------------------------- exception counter
    /** Called from the exception handler: count errors per minute (kept for an hour). */
    public static function countException(): void
    {
        try {
            $k = 'watchdog:exc:'.intdiv(time(), 60);
            Cache::add($k, 0, now()->addHour());
            Cache::increment($k);
        } catch (Throwable) {
        }
    }

    public static function exceptionsSince(int $minutes): int
    {
        $now = intdiv(time(), 60);
        $n = 0;
        for ($i = 0; $i < $minutes; $i++) {
            $n += (int) Cache::get('watchdog:exc:'.($now - $i), 0);
        }

        return $n;
    }
}
