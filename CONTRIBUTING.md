# Contributing to shoemoneyx 🤝

Thanks for helping. Here is how work lands.

## Ground rules

- **No proprietary strategies in this repo.** Strategies are user data, not source code. Ship examples, not edges.
- **No live-trading credentials anywhere in the tree.** Not in fixtures, not in docs, not in screenshots.
- Small PRs beat big ones. One behavior per PR.
- Every behavior change comes with a test. `php artisan test` must be green (never `--parallel`).

## Setup

```bash
composer install
npm ci
cp .env.example .env && php artisan key:generate
php artisan migrate
npm run build
php artisan test
npm run test:frontend
```

**Font Awesome Pro requirement:** `npm ci` and `npm run build` require the four Font Awesome Pro 7.3.1 npm tarballs in `.fa-pro/` (licensed, not redistributable). Contributors: fork PRs cannot run the image build workflow because the FA_PRO_KEY secret is not available to untrusted code. Use the published `ghcr.io` images for Docker-based development.

## Exchange adapters 🔌

Exchanges live in their own packages and implement the core contracts. To add one:

1. Implement `App\Exchange\Contracts\Exchange` (id, name, capabilities, marketData, account, executor,
   credentials, perpSpec) plus its `MarketData`, `Account`, and `Credentials` contracts, reusing the
   existing `App\Desk\Execution\Executor` interface for order placement — do not duplicate it.
2. Record fixtures from the real venue's public market data, no API key required:
   `php artisan exchange:record <id> <product>` (e.g. `php artisan exchange:record kraken BTC-USD`).
   This writes `tests/Exchange/Fixtures/<id>/{products,product,candles,ticker,trades,book}.json` —
   commit them.
3. Extend `Tests\Exchange\Conformance\ExchangeConformanceTestCase` with a small subclass, e.g.
   `tests/Exchange/Conformance/KrakenConformanceTest.php`, that wraps your adapter in
   `Tests\Exchange\Conformance\RecordedExchange` pointed at the fixture directory and names the
   sample product id. See `CoinbaseConformanceTest.php` for the pattern.
4. Run `php artisan test --filter Conformance` — it must pass fully, with no network calls and no
   credentials.
5. Open a PR titled `feat(exchange): <name>`. Adapters that pass the conformance suite get listed in the exchange directory.

An adapter PR without a passing conformance run will not be merged.

### Badge rules

The exchange directory (`/exchanges` in the app, `GET /api/exchanges`) badges every adapter
`passed` or `unverified`. An adapter is **conformant** only when BOTH of these are checked into
the repo:

1. a recorded fixture directory, `tests/Exchange/Fixtures/<id>/`, and
2. a conformance test class for it, `tests/Exchange/Conformance/<Id>ConformanceTest.php`.

Everything else — an adapter with no fixtures, no test class, or only one of the two — is badged
`unverified`, even if the adapter itself works fine. This is a checked-into-the-repo-and-CI-runs-it
rule, not a vibes rule: a PR that adds an adapter without both pieces (step 2 and step 3 above)
merges, if it merges at all, as `unverified`. Add the fixtures and the test in the same PR to land
as `passed`.

`App\Exchange\ConformanceStatus` is the one place that rule lives (a plain filesystem check — it
never autoloads or runs PHPUnit) — read it before changing where fixtures or conformance tests
live.

## Commits

Conventional style: `feat:`, `fix:`, `refactor:`, `docs:`, `test:`, `chore:`. Emojis welcome.

## Reporting bugs

Open an issue with steps to reproduce, expected vs actual, and your exchange + timeframe. Strip any keys or account ids before pasting logs.
