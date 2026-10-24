<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Desk\Heartbeat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Scheduled every minute; proves a queue worker is actually pulling jobs (the queue healthcheck reads its file). */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        Heartbeat::beat('queue');
    }
}
