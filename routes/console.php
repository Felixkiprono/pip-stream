<?php

use App\Jobs\QueueHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reliability (compose service "scheduler" runs schedule:work): a heartbeat job proves the queue
// worker runs, and the watchdog alerts the operators when anything stops.
Schedule::job(new QueueHeartbeat)->everyMinute()->name('queue-heartbeat');
Schedule::command('pipstream:watchdog')->everyMinute()->withoutOverlapping(5);
