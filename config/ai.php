<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Built-in AI agent
    |--------------------------------------------------------------------------
    |
    | Every model call the desk makes goes through App\Ai\Gate, which reads the
    | limits here. The nudge is what the builder offers when the operator is on
    | a free model and is about to ask for something a free model does badly.
    |
    */

    'rate_limit' => [
        'per_minute' => (int) env('AI_RATE_LIMIT_PER_MINUTE', 20),
        'per_day' => (int) env('AI_RATE_LIMIT_PER_DAY', 500),
    ],

    'abuse' => [
        'errors_per_hour' => (int) env('AI_ABUSE_ERRORS_PER_HOUR', 50),
    ],

    // The 24/7 worker: how much history each unattended backtest covers, and how
    // long a strategy's last run stays fresh enough to skip the next tick.
    'loop' => [
        'days' => (int) env('AI_LOOP_DAYS', 7),
        'interval_minutes' => (int) env('AI_LOOP_INTERVAL_MINUTES', 60),
    ],

    // Authoritative list for the builder's "Recommended" group: these stay on offer
    // whether or not OpenRouter's live catalogue happens to return pricing for them.
    'recommended' => [
        'deepseek/deepseek-v4-flash-vision-exp',
        'deepseek/deepseek-v4-flash',
    ],

    'nudge' => [
        'model' => 'deepseek/deepseek-v4-flash-vision-exp',
        'reason' => 'Backtest review reads charts and long results; the flash model does it for pennies.',
    ],

];
