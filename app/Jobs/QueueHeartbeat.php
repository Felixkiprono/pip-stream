<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

/** Queued by the scheduler every minute. When a queue worker runs it, the queue is alive. */
class QueueHeartbeat implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        Cache::put('pipstream:queue:beat', microtime(true), now()->addDay());
    }
}
