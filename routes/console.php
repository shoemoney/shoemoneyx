<?php

use App\Desk\Heartbeat;
use App\Jobs\QueueHeartbeat;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduler — alternative to the long-running `desk:run` loop.
|--------------------------------------------------------------------------
| Either run `php artisan desk:run` under a supervisor, OR run
| `php artisan schedule:work` and let these fire. Not both.
*/
// withoutOverlapping(N): the mutex expires after N minutes (default is 24h), so a schedule container killed
// mid-command stops skipping that command within N minutes; docker/entrypoint.sh also clears them at boot.
if (config('desk.use_scheduler')) {
    Schedule::command('market:sync-products')->hourly()->withoutOverlapping(30);
    Schedule::command('market:sync-candles --hours=72')->everyMinute()->withoutOverlapping(10);
    Schedule::command('desk:risk')->everyMinute()->withoutOverlapping(10);
    Schedule::command('desk:cycle')->everyFiveMinutes()->withoutOverlapping(10);
}
Schedule::command('desk:report --send')->dailyAt('23:59');
Schedule::command('agent:backtest-loop')->everyFifteenMinutes()->withoutOverlapping(60);
Schedule::command('strategies:sync')->everySixHours()->withoutOverlapping(60);
Schedule::command('hub:report-contests')->everyMinute()->withoutOverlapping(10);
Schedule::command('model:prune')->daily();
Schedule::command('queue:prune-failed --hours='.max(1, (int) config('desk.retention.failed_jobs_hours')))->daily();
Schedule::command('queue:prune-batches --hours='.max(1, (int) config('desk.retention.job_batches_hours')))->daily();
// Container healthchecks: the schedule file proves this loop ticks, the queue file proves a worker pulls jobs.
Schedule::call(fn () => Heartbeat::beat('schedule'))->everyMinute()->description('heartbeat:schedule');
Schedule::job(new QueueHeartbeat)->everyMinute()->description('heartbeat:queue');
