<?php

namespace App\Pipstream;

use App\Models\BrokerAccount;
use Throwable;

/**
 * "Ping MetaTrader": is the customer's terminal answering right now?
 *
 * The EA calls /ea/v1/sync about once a second. A ping leaves a mark in Redis
 * ({p}:eares:{id}:ping = when it was asked); the EA's next call answers it (EaController::sync
 * calls answer()), and the time between the two is how long MetaTrader took to answer.
 * No answer within a few seconds means it is offline, and the last check-in says since when.
 * Used by the Brokers screen, the bot (/ping, "📶 Ping MetaTrader") and Admin → customer.
 */
class EaPing
{
    public const WAIT_S = 4.0;

    public function __construct(private Bus $bus) {}

    private static function k(string $id, string $suffix): string
    {
        return config('pipstream.stream_prefix').":eares:{$id}:{$suffix}";
    }

    /** Called from the EA's sync: answer a waiting ping. */
    public static function answer($redis, string $id): void
    {
        $asked = $redis->get(self::k($id, 'ping'));
        if (is_string($asked) && $asked !== '') {
            $redis->del(self::k($id, 'ping'));
            $ms = max(1, (int) round((microtime(true) - (float) $asked) * 1000));
            $redis->set(self::k($id, 'pong'), (string) $ms, ['ex' => 60]);
        }
    }

    /**
     * Ping these EA accounts together and wait for their answers.
     *
     * @param  iterable<BrokerAccount>  $brokers
     * @return array<int, array{broker_id:string, label:string, online:bool, ms:?int, seen_s:?int, text:string}>
     */
    public function run(iterable $brokers, float $wait = self::WAIT_S): array
    {
        $r = $this->bus->redis()->client();
        $todo = [];
        foreach ($brokers as $b) {
            if ($b->connector !== 'ea_bridge') {
                continue;
            }
            try {
                $r->del(self::k($b->id, 'pong'));
                $r->set(self::k($b->id, 'ping'), (string) microtime(true), ['ex' => 30]);
            } catch (Throwable) {
            }
            $todo[$b->id] = $b;
        }
        $ms = [];
        $until = microtime(true) + $wait;
        while ($todo && count($ms) < count($todo)) {
            foreach ($todo as $id => $b) {
                if (! isset($ms[$id]) && is_string($v = rescue(fn () => $r->get(self::k($id, 'pong')), null, false)) && $v !== '') {
                    $ms[$id] = (int) $v;
                }
            }
            if (count($ms) >= count($todo) || microtime(true) >= $until) {
                break;
            }
            $this->pause();
        }

        $out = [];
        foreach ($todo as $id => $b) {
            rescue(fn () => $r->del(self::k($id, 'ping')), null, false);  // nobody answered: don't leave it for later
            $seen = rescue(fn () => $r->get(self::k($id, 'seen')), null, false);
            $seenS = is_string($seen) && $seen !== '' ? max(0, time() - (int) $seen) : null;
            $online = isset($ms[$id]);
            $label = $b->label ?: 'MetaTrader';
            $out[] = ['broker_id' => $id, 'label' => $label, 'online' => $online, 'ms' => $ms[$id] ?? null, 'seen_s' => $online ? 0 : $seenS,
                'text' => $online
                    ? "{$label}: ✅ online, answered in ".self::human($ms[$id])
                    : "{$label}: 🔌 not answering. ".($seenS === null ? 'It has not checked in for over a day.' : 'Last check-in '.self::ago($seenS).'.')
                        .' Check that the computer is on, MetaTrader is open and the PipStream EA is on a chart with Algo Trading on.'];
        }

        return $out;
    }

    protected function pause(): void
    {
        usleep(150_000);
    }

    public static function human(int $ms): string
    {
        return $ms < 1000 ? "{$ms} ms" : round($ms / 1000, 1).' s';
    }

    public static function ago(int $s): string
    {
        return match (true) {
            $s < 90 => "{$s} seconds ago",
            $s < 5400 => round($s / 60).' minutes ago',
            $s < 172800 => round($s / 3600).' hours ago',
            default => round($s / 86400).' days ago',
        };
    }
}
