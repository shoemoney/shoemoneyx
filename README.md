# shoemoneyx 📈

An open-source crypto paper-trading desk with a backtest farm, a walk-forward optimizer, and sub-minute candles built from the raw trade tape. Bring your own strategy. Bring your own exchange.

Laravel 13 · Vue 3 · Redis · MySQL/MariaDB · TradingView charts (bring your own library).

> **NFA.** Paper first. Start with a number you are fine watching hit zero.

## What it does

```
SCAN → VET → SIZE → FILLS          one cycle, every scan.poll_seconds
                         RISK      own timer, final authority
CHIEF                              health, heartbeats, halt switch, reports
```

- **110 exchanges** behind one contract. Set `EXCHANGE=kraken` and go.
- **Paper desk** that simulates market orders against the live bid/ask with configurable slippage. No exchange key needed.
- **Candles you can't get from an exchange**: 15s, 30s, 33s, 41s, 45s, 49s, 90s built from the trade tape, plus every standard timeframe.
- **Backtester** that replays a strategy over any window, and a **farm** that fans a parameter grid out to every worker box you own.
- **Optimizer** that runs random or hill-climb candidates per coin, trains and tests walk-forward, and only promotes out-of-sample winners.
- **Dashboard** with live chart, positions, runs, backtests, and every tunable editable in place.
- **Live mode** behind a double confirm (`DESK_MODE=live` and `DESK_LIVE_CONFIRM=yes`). Nothing is sent without both.

**110 exchanges out of the box.** Coinbase (spot + US perpetual futures) has a native adapter, and a generic [ccxt](https://github.com/ccxt/ccxt) adapter covers everything ccxt speaks for spot market data and orders: Binance, Kraken, Bybit, OKX, KuCoin, Bitget, Gate, HTX, MEXC, Bitfinex, Gemini, Bitstamp and the rest. Full roster in `docs/EXCHANGES.md`.

## Run it with Docker 🐳

Zero PHP, zero Node. Docker gives you the whole desk — dashboard, trading loop, queue, scheduler, Reverb websockets, Coinbase feeder, MariaDB, Redis — behind HTTPS on one port.

```bash
git clone https://github.com/shoemoney/shoemoneyx.git && cd shoemoneyx
docker/up.sh                  # or: bin/desk compose
open https://localhost
```

First run generates `.env` and prints your `MASTER_PASSWORD` once — save it, it gates every page and the API. Rerunning `docker/up.sh` is a no-op except starting containers.

Update to the latest image:

```bash
docker compose pull && docker compose up -d
```

## Quick start (no Docker)

```bash
cp .env.example .env          # set DB_*, keep DESK_MODE=paper
bin/desk setup                # composer, npm, key, migrate, product sync, candle backfill
bin/desk dev                  # vite + artisan serve + queue worker + desk:run
open http://localhost:8000
```

First run lands you on `/onboarding` — set a master password (or skip it for a trusted local desk), connect an OpenRouter key, pick an exchange, optionally import a community strategy, and launch in paper mode, all in under five minutes.

Charts need the TradingView Charting Library, which is free but cannot be redistributed. See `public/charting_library/README.md`.

## Commands

| Command | What |
|---|---|
| `php artisan desk:run` | the CHIEF loop: products hourly, candles every minute, RISK every 60s, cycle every 5 min |
| `php artisan desk:cycle` | one SCAN→VET→SIZE→FILLS pass |
| `php artisan desk:risk` | one RISK sweep |
| `php artisan desk:ctl status\|halt\|resume\|stop\|close <PRODUCT\|all>\|paper-reset\|set <key> <v>\|get <key>` | controls |
| `php artisan desk:backtest --products=BTC-USD,SOL-USD --days=30 --cash=1000` | replay a strategy |
| `php artisan desk:sweep --queue` | fan a parameter grid out to the farm |
| `php artisan desk:optimize --space=core\|wide --mutate=N` | walk-forward optimizer |
| `php artisan desk:coin show\|set\|clear` | per-coin parameter overrides |
| `php artisan market:sync-products` / `market:backfill --days=90` / `market:backfill-trades --timeframes=33s,41s` | data |
| `php artisan coinbase:account --key=… --secret=…` / `--test` | store (encrypted) and test an exchange key |

`bin/desk` wraps the common ones.

## Writing a strategy

The contract is `app/Desk/Contracts/Strategy.php`. Every decision sees one `ProductStats` row: volumes, price changes, buy/sell tape, spread, book depth, indicators.

Two strategies ship as examples. `MeanReversionStrategy` is the default. `CustomStrategy` is the template: override any of `scan()`, `vet()`, `size()`, `risk()`, add tunables in `defaults()` and they appear on the Settings page. Select with `DESK_STRATEGY=custom` or the dropdown.

Parameters layer as `config/desk.php` ← strategy `defaults()` ← the `settings` table ← `per_product.<PAIR>.<key>`.

This repository ships example strategies only. Your edge is yours.

## Farm

Any machine that can reach the desk host's Redis can be a worker: `bin/desk worker N`, or the Docker image with `ROLE=worker`. Roster and expected worker counts come from `FARM_HOSTS` and `FARM_EXPECTED_WORKERS` in `.env`. Details in `docs/FARM.md`.

## Layout

```
app/Desk/                Desk.php (pipeline)  Chief.php  Backtester.php  Optimizer/  Settings.php
app/Desk/Contracts       Strategy.php
app/Desk/Data            ProductStats  Candidate  Verdict  SizeDecision  RiskDecision  Bank
app/Desk/Execution       Executor  PaperExecutor  Perps (spec delegates to the active exchange)
app/Desk/Strategies      MeanReversionStrategy  CustomStrategy
app/Exchange/Contracts   Exchange  MarketData  Account  Credentials — the exchange-agnostic layer
app/Exchange             Capabilities  ExchangeRegistry (config('exchanges.drivers') -> adapter)
app/Exchange/Coinbase    the Coinbase adapter: CoinbaseExchange, CoinbaseMarketData, CoinbaseExecutor,
                          CoinbasePerpsExecutor, CoinbaseAccountAdapter, CoinbaseCredentials
app/Exchange/Coinbase/Api JWT, HTTP client, authenticated Coinbase API (CoinbaseService)
app/Services/Market      market data callers, product sync, candle store, trade backfill
app/Services/Indicators  indicator math
app/Http/Controllers/Api status, positions, runs, desk, settings, backtests, farm, UDF datafeed
resources/js             Vue SPA
docs/COINBASE_DOCS.md    Coinbase docs map, read before touching any Coinbase call
```

## Adding an exchange

Exchanges are pluggable behind `app/Exchange/Contracts/Exchange.php`. To add one:

1. Implement `Exchange` (and its `MarketData`, `Account`, `Credentials` contracts, plus the existing
   `App\Desk\Execution\Executor` for order placement) under `app/Exchange/<YourExchange>/`.
2. Register the driver in `config/exchanges.php` under `drivers`, keyed by its `id()`.
3. Set `EXCHANGE=<id>` in `.env` (or leave it on `coinbase`, the default) to make it active.

Callers never reference a concrete exchange class — they type-hint `MarketData` or `Exchange` and the
active adapter is resolved by `App\Providers\ExchangeServiceProvider`.

Most venues need no class at all: the generic ccxt adapter puts ~100 REST exchanges behind the same
contracts, so enabling one is an env var. See [docs/EXCHANGES.md](docs/EXCHANGES.md) for native vs
ccxt adapters, `CCXT_EXCHANGES`, the `exchange:list` and `exchange:credentials` commands, and the
spot-only caveat.

## Tests

```bash
php artisan test
```

## Contributing

See `CONTRIBUTING.md`. Exchange adapters are the most wanted contribution. Security issues go to the address in `SECURITY.md`.

## License

MIT.
