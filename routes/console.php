<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduler — alternative to the long-running `desk:run` loop.
|--------------------------------------------------------------------------
| Either run `php artisan desk:run` under a supervisor, OR run
| `php artisan schedule:work` and let these fire. Not both.
*/
if (env('DESK_USE_SCHEDULER', false)) {
    Schedule::command('market:sync-products')->hourly()->withoutOverlapping();
    Schedule::command('market:sync-candles --hours=72')->everyMinute()->withoutOverlapping();
    Schedule::command('desk:risk')->everyMinute()->withoutOverlapping();
    Schedule::command('desk:cycle')->everyFiveMinutes()->withoutOverlapping();
}
Schedule::command('desk:report --send')->dailyAt('23:59');
Schedule::command('agent:backtest-loop')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('strategies:sync')->everySixHours()->withoutOverlapping();
Schedule::command('hub:report-contests')->everyMinute()->withoutOverlapping();
