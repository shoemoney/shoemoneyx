<?php

declare(strict_types=1);

return [
    // Which driver below is live. One env knob swaps the whole venue.
    'active' => env('EXCHANGE', 'coinbase'),

    'drivers' => [
        'coinbase' => \App\Exchange\Coinbase\CoinbaseExchange::class,
    ],

    // Generic ccxt adapters, one per ccxt exchange id. Comma-separated; see docs/EXCHANGES.md.
    'ccxt' => [
        'enabled' => env('CCXT_EXCHANGES', 'binance,kraken,bybit,okx,kucoin'),
    ],
];
