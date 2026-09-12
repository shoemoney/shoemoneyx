# Coinbase (CDP) documentation — where to look first

**Rule for anyone (human or AI) working on shoemoneyx: before touching anything that talks to Coinbase
(market data, orders, websocket, fees, auth), consult this file, then the exact page it points to.
Do not guess endpoint shapes from memory.**

## Entry points

| What | URL | Use when |
|---|---|---|
| Docs index (every page, one line each) | https://docs.cdp.coinbase.com/llms.txt | Discovering which page covers a topic. Fetch this first. |
| Full docs in one file | https://docs.cdp.coinbase.com/llms-full.txt | A question spans several areas. Large — grep it, don't read it whole. |
| Agent skill file (agentskills.io format) | https://docs.cdp.coinbase.com/skill.md | Loading CDP capabilities/constraints into an agent. Installed by hand at `~/.claude/skills/coinbase-cdp/SKILL.md` (2026-09-05; `npx skills add` rejects both the root and `/skill.md` URLs — `curl -sL https://docs.cdp.coinbase.com/skill.md -o` that path to refresh) |
| AGENTS.md | https://docs.cdp.coinbase.com/AGENTS.md | Agent-oriented overview of the platform. |
| Advanced Trade API section index (213 pages) | https://docs.cdp.coinbase.com/_llms/api-reference/coinbase-advanced-trade-api.md | Everything shoemoneyx actually uses lives under here. |
| Advanced Trade OpenAPI spec | https://docs.cdp.coinbase.com/api-reference/advanced-trade-api/rest-api/advanced-trade-spec.yaml | Exact request/response schemas. |
| Advanced Trade AsyncAPI (websocket) spec | https://docs.cdp.coinbase.com/api-reference/advanced-trade-api/advanced-trade-asyncapi.json | Channel message shapes for the feeder. |
| CDP Docs MCP server | `https://docs.cdp.coinbase.com/mcp` (search only, no API execution; registered in Claude Code as `cdp-docs`, user scope: `claude mcp add --transport http --scope user cdp-docs https://docs.cdp.coinbase.com/mcp`). Setup: https://docs.cdp.coinbase.com/get-started/build-with-ai/docs-for-ai/cdp-docs-mcp | Ongoing dev with an AI tool that supports MCP. |
| Coinbase for Agents (trading MCP/CLI) | https://docs.cdp.coinbase.com/coinbase-for-agents/overview , skill: https://docs.cdp.coinbase.com/coinbase-for-agents/skill.md | An LLM placing orders via `https://agents.coinbase.com/mcp` or the Coinbase CLI. **Not used by the desk** (adds latency/non-determinism); useful for reading the account fee tier without pasting keys. |

Any page URL + `.md` returns the markdown version (e.g. `.../orders/create-order.md`). Every docs page also
has a **Copy page ▾** menu: *Copy page as Markdown for LLMs*, *View as Markdown*, *Open in …*, *Copy MCP Server*.

## Pages shoemoneyx depends on (Advanced Trade)

Base: `https://docs.cdp.coinbase.com/api-reference/advanced-trade-api/`

### Public market data (no key) — `app/Exchange/Coinbase/CoinbaseMarketData.php`, `CandleStore.php`, `TradeBackfill.php`
- `rest-api/public/list-public-products.md` — universe (`ProductSync`)
- `rest-api/public/get-public-product.md`
- `rest-api/public/get-public-product-candles.md` — native granularities only: 1m 5m 15m 30m 1H 2H 6H 1D, max 350 candles/call. 2m/3m/4m/10m and 15s/30s/45s are built locally (`Candle::DERIVED`, `Candle::FROM_TRADES`).
- `rest-api/public/get-public-market-trades.md` — trade tape, `start`/`end` unix seconds, `limit` ≤ 1000 — used by `market:backfill-trades` for sub-minute bars.
- `rest-api/public/get-public-product-book.md` — spread / depth (`vet.max_spread_bps`)

### Authenticated (CDP key, ES256 JWT) — `app/Exchange/Coinbase/*`
- `rest-api/accounts/list-accounts.md`, `get-account.md` — balances (`coinbase:account`)
- `rest-api/fees/get-transaction-summary.md` — **your real maker/taker tier + 30-day volume. Check this before trusting `fees.taker_rate`.**
- `rest-api/orders/create-order.md` — order_configuration: `market_market_ioc`, `limit_limit_gtc` (`post_only` flag = maker), `limit_limit_gtd`, `sor_limit_ioc`, stop/bracket types
- `rest-api/orders/preview-orders.md` — dry run: fills, fees, slippage estimate (use in paper→live handoff)
- `rest-api/orders/edit-order.md`, `edit-order-preview.md` — re-price a resting limit (maker executor chase)
- `rest-api/orders/cancel-order.md` (batch), `get-order.md`, `list-orders.md`, `list-fills.md` — reconcile fills/fees
- `rest-api/orders/close-position.md` — futures only, ignore for spot
- `rest-api/products/get-best-bid-ask.md` — authed bid/ask for many products in one call (cheaper than N book calls)

### Websocket — `feeder/feed.mjs`
Public `wss://advanced-trade-ws.coinbase.com`; channel pages under `websocket/`:
- `ticker.md`, `ticker-batch.md` (batch = 5s cadence, lighter), `market-trades.md`, `candles.md` (5m only), `heartbeats.md`, `level2.md`, `product-status.md`
- `user.md` — **authenticated** channel: your own order status + fills in real time. Needed for the maker executor so we don't poll `list-orders`. Auth headers helper: `sdks/cdp-sdks-v2/typescript/auth/WebSocket/getWebSocketAuthHeaders.md`.

### Limits / auth
- Rate limits: private REST 30 req/s, public REST 10 req/s, websocket 750 msg/s per IP, 8 subscriptions/product/channel — confirm on `rest-api/rate-limits` / websocket overview pages before raising `universe.max_products`.
- Auth: CDP API key (name + EC private key) → JWT ES256, `uri` claim `METHOD host/path`, 2-minute expiry. Key is stored by `php artisan coinbase:account --key= --secret=` (the user runs this; never paste keys into chat).

## Working habits
1. `curl -s https://docs.cdp.coinbase.com/llms.txt | grep -i <topic>` to find the page, then fetch `<page>.md`.
2. For request/response fields, prefer the OpenAPI yaml over prose.
3. Any new Coinbase call in code gets a comment with the doc page path so the next reader can check it.
