<?php

namespace App\Console\Commands;

use App\Pipstream\Bus;
use App\Pipstream\EventHandler;
use Illuminate\Console\Command;

/** Long-running worker (compose service "events"): engine events -> alerts, login answers. */
class ConsumeEvents extends Command
{
    protected $signature = 'pipstream:consume {--once : Process what is waiting, then exit}';

    protected $description = 'Consume PipStream engine events (trades, skips, Telegram login answers)';

    private bool $stop = false;

    public function handle(Bus $bus, EventHandler $handler): int
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop = true);
            pcntl_signal(SIGINT, fn () => $this->stop = true);
        }
        $consumer = gethostname() ?: 'laravel';
        $once = (bool) $this->option('once');
        $rounds = 0;
        $this->info("consuming as {$consumer}");
        $bus->consume(Bus::CONSUME, $handler, $consumer,
            function () use ($once, &$rounds) {
                return $this->stop || ($once && $rounds++ >= 1);
            }, blockMs: $once ? 200 : 2000);

        return self::SUCCESS;
    }
}
