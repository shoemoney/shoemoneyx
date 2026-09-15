<?php

declare(strict_types=1);
use App\Desk\Strategies\CustomStrategy;
use App\Desk\Strategies\MeanReversionStrategy;

/*
|--------------------------------------------------------------------------
| shoemoneyx desk configuration
|--------------------------------------------------------------------------
| Everything here is a DEFAULT. Values can be overridden at runtime from the
| dashboard (stored in the `settings` table) — see App\Desk\Settings.
|
| The pipeline mirrors the "six seated bots" desk:
|   SCAN -> VET -> SIZE -> FILLS -> RISK   (BOOK is a separate, optional stage)
*/

return [

    // paper | live  — paper never touches Coinbase order endpoints.
    'mode' => env('DESK_MODE', 'paper'),

    // Strategy key from the `strategies` map below.
    'strategy' => env('DESK_STRATEGY', 'mr'),

    'strategies' => [
        'mr' => MeanReversionStrategy::class,
        'custom' => CustomStrategy::class,
        'json' => \App\Desk\Strategies\JsonPluginStrategy::class,
    ],

    // Which Coinbase products form the tradeable universe.
    'universe' => [
        'quote_currencies' => ['USD', 'USDC'],
        'exclude' => [],
        // Stablecoins never make the universe (nothing to trade).
        'stable_bases' => ['USDT', 'USDC', 'DAI', 'PAX', 'GUSD', 'PYUSD', 'EURC', 'USD1', 'RLUSD', 'FDUSD', 'TUSD', 'BUSD', 'USDS', 'USDE', 'PAXG'],
        // Only products with >= this much 24h quote volume are even fetched into SCAN.
        'min_volume_24h_usd' => (float) env('DESK_MIN_VOLUME_24H', 250000),
        // Cap the number of products we compute stats for each cycle (rate limits).
        'max_products' => (int) env('DESK_MAX_PRODUCTS', 120),
    ],

    /*
    | Perpetual futures (Coinbase US perps via Coinbase Financial Markets).
    | Signals are still computed on the SPOT tape (BTC-USD candles); orders go to the mapped perp.
    | docs/COINBASE_DOCS.md → Advanced Trade "products?product_type=FUTURE", venue cde, contract_expiry_type PERPETUAL.
    | Whole contracts only. Fee ≈ 0.02% per contract with a $0.15 floor; margin 10% intraday, ~25-33% overnight.
    */
    'perps' => [
        'enabled' => (bool) env('DESK_PERPS', false),
        // Entries under one contract are rejected and fills are whole nano contracts, matching CFM; false restores fractional paper fills.
        'whole_contracts' => (bool) env('DESK_PERPS_WHOLE_CONTRACTS', true),
        'map' => [
            'BTC-USD' => ['product_id' => 'BIP-20DEC30-CDE', 'contract_size' => 0.01],
            'ETH-USD' => ['product_id' => 'ETP-20DEC30-CDE', 'contract_size' => 0.1],
            'SOL-USD' => ['product_id' => 'SLP-20DEC30-CDE', 'contract_size' => 5.0],
            'XRP-USD' => ['product_id' => 'XPP-20DEC30-CDE', 'contract_size' => 500.0],
            'LINK-USD' => ['product_id' => 'LNP-20DEC30-CDE', 'contract_size' => 50.0],
            'DOGE-USD' => ['product_id' => 'DOP-20DEC30-CDE', 'contract_size' => 5000.0],
            'ADA-USD' => ['product_id' => 'ADP-20DEC30-CDE', 'contract_size' => 1000.0],
            'AVAX-USD' => ['product_id' => 'AVP-20DEC30-CDE', 'contract_size' => 10.0],
            'SUI-USD' => ['product_id' => 'SUP-20DEC30-CDE', 'contract_size' => 500.0],
            'LTC-USD' => ['product_id' => 'LCP-20DEC30-CDE', 'contract_size' => 5.0],
            // Expansion 2026-09-05: cleared the 5% train / positive test bar on the first core matrix.
            'ZEC-USD' => ['product_id' => 'ZEC-20DEC30-CDE', 'contract_size' => 1.0],
            'HYPE-USD' => ['product_id' => 'HYP-20DEC30-CDE', 'contract_size' => 10.0],
            'NEAR-USD' => ['product_id' => 'NER-20DEC30-CDE', 'contract_size' => 500.0],
            'BCH-USD' => ['product_id' => 'BCP-20DEC30-CDE', 'contract_size' => 1.0],
            'ENA-USD' => ['product_id' => 'ENA-20DEC30-CDE', 'contract_size' => 5000.0],
            'XLM-USD' => ['product_id' => 'XLP-20DEC30-CDE', 'contract_size' => 5000.0],
            'HBAR-USD' => ['product_id' => 'HEP-20DEC30-CDE', 'contract_size' => 5000.0],
            'ONDO-USD' => ['product_id' => 'OND-20DEC30-CDE', 'contract_size' => 1000.0],
        ],
        // Which mapped coins the desk actually trades (comma list). Empty = every mapped coin.
        'active' => array_values(array_filter(array_map('trim', explode(',', (string) env('DESK_PERPS_ACTIVE', ''))))),
        // Keep a margin cushion: refuse a new entry if it would use more than this share of available margin.
        'max_margin_use_pct' => (float) env('DESK_PERPS_MAX_MARGIN_PCT', 50),
        // CFM initial margin by window, in percent. Intraday is enrolled-only and cheaper; every other window is conservative.
        'initial_margin_pct' => ['intraday' => 12, 'overnight' => 35, 'weekend' => 35, 'transition' => 35, 'unknown' => 35],
        // Overnight maintenance margin, in percent — below initial; MarginBook and the backtester liquidate below it.
        'maintenance_margin_pct' => 25,
        // Route paper fills through the same margin model as live (post initial margin, liquidate on maintenance breach); off restores the old cash-notional / 1x-collateral paper paths.
        'paper_margin' => (bool) env('DESK_PERPS_PAPER_MARGIN', true),
        // Refuse new risk once the liquidation buffer (balance_summary.liquidation_buffer_percentage) drops below this.
        'min_liquidation_buffer_pct' => (float) env('DESK_PERPS_MIN_LIQ_BUFFER', 30),
        // RISK closes the worst open position once the buffer drops below this (tighter than the entry floor above).
        'deleverage_buffer_pct' => (float) env('DESK_PERPS_DELEVERAGE_BUFFER', 15),
        // Refuse new intraday-margin risk this many minutes before the 16:00 ET flip to overnight margin.
        'flip_guard_minutes' => 30,
        // The endpoint rejects an unspecified profile; RETAIL_INTRADAY_MARGIN_1 once the account is enrolled for intraday margin.
        'margin_profile_type' => env('DESK_PERPS_MARGIN_PROFILE', 'MARGIN_PROFILE_TYPE_RETAIL_REGULAR'),
    ],

    'paper' => [
        'starting_cash' => (float) env('DESK_PAPER_CASH', 1000),
        // Simulated market-order slippage in bps applied against best ask/bid.
        'slippage_bps' => (float) env('DESK_PAPER_SLIPPAGE_BPS', 1),   // on top of the real bid/ask; Coinbase majors quote 0.2–3 bps wide
    ],

    // Post-only shadow A/B (paper only): what a resting maker limit would have done instead of the
    // taker fill the desk actually made. See App\Desk\Execution\PostOnlyShadows.
    'post_only' => [
        'shadow' => (bool) env('DESK_POST_ONLY_SHADOW', false),
        'chase_pct' => (float) env('DESK_POST_ONLY_CHASE_PCT', 0.05),
        'ttl_seconds' => (int) env('DESK_POST_ONLY_TTL_SECONDS', 300),
        'bar_timeframe' => env('DESK_POST_ONLY_BAR_TIMEFRAME', '13s'),
    ],

    // See App\Services\Market\CandleStore.
    'candles' => [
        // How long the open-window MAX(updated_at) freshness probe is trusted before re-querying.
        // A range scan until migration 2026_09_06_000400 (product_id, timeframe, updated_at index)
        // lands, so kept above the old flat 5s default; override down once the index is in place.
        'probe_ttl_seconds' => (int) env('DESK_CANDLE_PROBE_TTL_SECONDS', 15),
    ],

    'optimizer' => [
        // Optimizer-created backtest rows are deleted after this many hours (payloads are stripped after 2).
        'retain_hours' => (int) env('DESK_OPTIMIZER_RETAIN_HOURS', 24),
    ],

    'fees' => [
        // Coinbase Advanced Trade taker fee (base tier). Override once you know your tier.
        'taker_rate' => (float) env('DESK_TAKER_FEE', 0.006),
        'maker_rate' => (float) env('DESK_MAKER_FEE', 0.004),
        // Hourly funding on open notional (Coinbase US perps charge this; positive = longs pay shorts).
        // A constant until a real funding-rate history exists: 0.00125%/h ≈ 0.01% per 8h, the long-run
        // crypto perps average.
        'funding_hourly_pct' => (float) env('DESK_FUNDING_HOURLY_PCT', 0.00125),
        // Minimum fee floor in USD (Coinbase has none; kept for parity with the desk rule).
        'floor_usd' => (float) env('DESK_FEE_FLOOR', 0.0),
        // Perps-style per-contract minimum (Coinbase US nano perps: $0.15 per contract, BTC-PERP = 0.01 BTC,
        // ETH-PERP = 0.1 ETH). fee = max(notional × rate, contracts × per_contract). 0 = off. Backtest/paper only for now.
        'per_contract_usd' => (float) env('DESK_FEE_PER_CONTRACT', 0.0),
        'contract_usd' => (float) env('DESK_CONTRACT_USD', 0.0),
        // Max effective round-trip fee before FILLS refuses (FEE_FLOOR).
        'max_effective_fee_pct' => (float) env('DESK_MAX_FEE_PCT', 0.02),
    ],

    'bank' => [
        // Portion of equity that never trades ("the locked bag").
        'locked_pct' => (float) env('DESK_LOCKED_PCT', 0.20),
        // Reserve for hosting/token bills, paid out of realised profit first.
        'reserve_usd' => (float) env('DESK_RESERVE_USD', 0),
    ],

    'scan' => [
        'poll_seconds' => 300,
        'max_candidates' => 10,
        'min_age_hours' => 24,            // pairs younger than this are held, not ranked
        'liquidity_floor_usd' => 100000,  // 24h volume floor
        'min_buy_sell_ratio' => 1.0,      // sells > buys in last two windows drops candidate
        // Wall-clock budget for the shortlist's order-book fetches (each up to coinbase.timeout).
        // Once spent, remaining shortlist candidates scan without book depth rather than stalling the cycle.
        'book_fetch_budget_seconds' => (float) env('DESK_BOOK_FETCH_BUDGET_SECONDS', 5.0),
    ],

    'vet' => [
        // "already priced": if the 1h move exceeds this the signal is stale
        'max_price_change_h1_pct' => 12.0,
        // break-even move required (round-trip fees + slippage) must be under this
        'max_breakeven_move_pct' => 2.0,
        // max share of 24h volume a position may represent (liquidity vs size)
        'max_pct_of_volume_24h' => 0.5,   // PERCENT of 24h volume a ticket may represent
        // rug shape: young + volume falling
        'min_rejections_per_day_warning' => 10,
    ],

    'size' => [
        'kelly_cap_pct' => 0.06,          // 6% of the book, ceiling not target
        'kelly_fraction' => 0.5,          // half-Kelly on the strategy's edge estimate
        'min_ticket_usd' => 10.0,
        'max_open_positions' => 7,
        'max_slippage_bps' => 50,
        'allow_one_add_after_pct' => 50.0, // one add allowed on retest after +50%
    ],

    'risk' => [
        'poll_seconds' => 60,
        // THE RULE: h6 volume / (h24 volume / 4) < 0.20 -> CLOSE
        'volume_ratio_close' => 0.20,
        // Safety rails not in the post but sensible on spot:
        'hard_stop_pct' => -12.0,         // close if PnL < this %
        'trail_activate_pct' => 60.0,     // "mark at +60% and let it run"
        'trail_giveback_pct' => 25.0,     // trailing stop giveback from peak once activated
        'max_hold_hours' => 72,
        'stale_data_retries' => 2,        // no answer -> retry twice, then CLOSE anyway
    ],

    'chief' => [
        'heartbeat_seconds' => 60,
        'missed_pings_restart' => 2,
        'deaths_per_hour_halt' => 2,
        'notify_move_pct' => 10.0,
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
    ],

    // Optional WorldMonitor context (local instance). Blank = disabled.
    'worldmonitor' => [
        'base_url' => env('WORLDMONITOR_URL', ''),
    ],

    // One master password gating every page (web session) and the API
    // (X-Desk-Token header or ?token=). Empty = gate off, private network only.
    'master_password' => env('MASTER_PASSWORD'),

    // Shown on the login page while the master password is still the bootstrap value (no
    // onboarding override yet) — e.g. a marketplace AMI sets this to point at the EC2 instance ID.
    'master_password_hint' => env('MASTER_PASSWORD_HINT'),

    // True on internet-exposed images: onboarding refuses to leave the desk password-less.
    'require_master_password' => (bool) env('DESK_REQUIRE_MASTER_PASSWORD', false),

    // Must be the literal string "yes" before the desk will send a real order.
    'live_confirm' => env('DESK_LIVE_CONFIRM', 'no'),

    // Gates the desk-loop schedule entries in routes/console.php (market:sync-products,
    // market:sync-candles, desk:risk, desk:cycle). true = drive them with `schedule:work`
    // instead of the long-running `desk:run` loop — never run both. Read via config() (not
    // env()) so the setting survives `artisan config:cache`/`optimize` in production.
    'use_scheduler' => (bool) env('DESK_USE_SCHEDULER', false),
];
