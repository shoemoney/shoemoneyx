<?php

declare(strict_types=1);

return [
    // Authenticated Advanced Trade API (orders, accounts). Needs a CDP API key.
    'base_url' => env('COINBASE_API_BASE_URL', 'https://api.coinbase.com/api/v3/brokerage'),
    'host' => 'api.coinbase.com',
    'jwt_expiry_seconds' => 120,

    // Public market-data endpoints (no key needed) — used for SCAN, candles, charts.
    'public_base_url' => env('COINBASE_PUBLIC_BASE_URL', 'https://api.coinbase.com/api/v3/brokerage/market'),

    // Single-key mode: put your CDP key here and `php artisan coinbase:account` stores it encrypted.
    'api_key_name' => env('COINBASE_API_KEY_NAME'),
    'api_private_key' => env('COINBASE_API_PRIVATE_KEY'),

    'timeout' => 30,
    'connect_timeout' => 10,
    'retry_attempts' => 3,
    'retry_delay' => 200,

    // Candle granularities we keep in MySQL (Coinbase enum => our timeframe key).
    'granularities' => [
        'ONE_MINUTE' => '1m',
        'FIVE_MINUTE' => '5m',
        'FIFTEEN_MINUTE' => '15m',
        'THIRTY_MINUTE' => '30m',
        'ONE_HOUR' => '1H',
        'SIX_HOUR' => '6H',
        'ONE_DAY' => '1D',
    ],
];
