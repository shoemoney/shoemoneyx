<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Community strategy sync
|--------------------------------------------------------------------------
| The desk pulls new/updated strategies from a public git repo — no account
| needed, no write access, just a manifest.json and per-strategy JSON files
| served over plain HTTP(S). See docs/STRATEGY_SYNC.md and
| App\Strategies\Sync\StrategySync.
*/

return [

    // Trailing slash added at read time if missing — every fetch appends a
    // relative path (manifest.json, strategies/<file>.json) to this.
    'source_url' => env(
        'STRATEGIES_SOURCE_URL',
        'https://git.shoemoney.ai/shoemoney/shoemoneyx-strategies/raw/branch/master/',
    ),

    // How long GET /api/strategies/sync/status caches its check() result, and
    // the interval the scheduled `strategies:sync` check runs at.
    'check_interval_minutes' => (int) env('STRATEGIES_CHECK_INTERVAL_MINUTES', 360),
];
