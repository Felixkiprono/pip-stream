<?php

namespace App\Console\Commands;

use App\Pipstream\Watchdog as Dog;
use Illuminate\Console\Command;

/** Scheduled every minute (routes/console.php): opens and closes incidents, emails the operators. */
class Watchdog extends Command
{
    protected $signature = 'pipstream:watchdog';

    protected $description = 'Check workers, engine, error rates and customers\' MetaTrader; alert on changes';

    public function handle(Dog $dog): int
    {
        $c = $dog->run();
        foreach ($c['opened'] as $k) {
            $this->warn("alert: {$k}");
        }
        foreach ($c['resolved'] as $k) {
            $this->info("recovered: {$k}");
        }

        return self::SUCCESS;
    }
}
