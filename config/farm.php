<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Backtest farm inventory
|--------------------------------------------------------------------------
| Optimizer workers on other machines pull from the desk host's Redis.
| FarmController reads this roster to turn `CLIENT LIST` connection counts
| into an estimated worker count per host, so a dark host stays visible.
|
| FARM_HOSTS is JSON: {"10.0.0.5": {"label": "desk", "role": "desk"}, ...}
| FARM_EXPECTED_WORKERS is JSON: {"10.0.0.5": 24, "default": 4}
*/

return [

    'hosts' => json_decode((string) env('FARM_HOSTS', '{}'), true) ?: [],

    'connections_per_worker' => (float) env('FARM_CONNECTIONS_PER_WORKER', 2),

    'expected_workers' => json_decode((string) env('FARM_EXPECTED_WORKERS', '{"default": 4}'), true) ?: ['default' => 4],

];
