# shoemoneyx → open source: the plan 🗺️

Order matters. Phase 0 gates everything. Check boxes as they land.

## Phase 0 — Purge & publish gate 🚫🔐
- [x] Untrack the TradingView charting library (license forbids redistribution), placeholder README explains install
- [x] Untrack 169 MB of trade-tape research parquet, ignore `research/`
- [x] Rebrand the indicator/panel to SMX; zero legacy strategy names left in code
- [x] Farm inventory moved from hardcoded LAN IPs to `FARM_HOSTS` env
- [x] Delete every proprietary strategy class and every reference (registry, tests, docs, seeds)
- [x] Strip proprietary champion params from settings seeds / migrations / fixtures
- [x] Remove `docs/RESUME.md`, `docs/research/*`, strategy review docs, `blog/` ops posts
- [x] Cherry-pick from `archive/feat/27s-38s-1p5pct-strategy-20260907`: JSON strategy runner (eef9259), OpenRouter picker (a26cf95, 7eac9ee), master password gate (c0acb1a). Strip anything SMX-specific while landing them
- [x] `git log -p` grep for keys, IPs (192.168.), hostnames, personal names. Scan done: no real secrets in history, but every proprietary strategy is. Decision: publish as a NEW repo from a single squashed commit of this tree. Never push this history
- [x] Add LICENSE (MIT or Apache-2.0, pick one), CONTRIBUTING.md, CODE_OF_CONDUCT.md, SECURITY.md
- [x] README rewrite: community positioning, "bring your own OpenRouter key," no ShoeMoney strategy lore
- [x] Delete the local archive tags and bundle once everything needed is recovered
- [x] Published: github.com/shoemoney/shoemoneyx (one squashed commit on `main`), mirrored at git.shoemoney.ai/shoemoney/shoemoneyx

## Phase 1 — Exchange modularity 🔌
- [x] Contracts: `MarketData`, `Execution`, `Account`, `Credentials` interfaces + exchange manifest (id, name, spot/perps/ws capabilities)
- [x] `ExchangeRegistry` + service-provider discovery; everything in `app/Desk` and `app/Services/Market` talks only to contracts (35 files reference Coinbase today)
- [x] Coinbase moved behind the contracts into `app/Exchange/Coinbase` (composer package split deferred until the contracts settle)
- [x] `shoemoneyx/exchange-ccxt` generic adapter (ccxt-php) for ~100 REST exchanges
- [x] `shoemoneyx/exchange-testkit`: abstract conformance suite + recorded-fixture replay so CI runs without keys
- [x] Canonicalize `MarketData` shapes in the adapters (lowercase `side`, `base_currency`/`quote_currency`, `[price,size]` book tuples) and move that normalization out of `exchange:record`; update ProductSync/CandleStore/ProductStatsBuilder consumers
- [x] Exchange directory page + "conformance passed" badge rules in CONTRIBUTING

## Phase 2 — Strategies as data 📦
- [x] JSON strategy schema (phases, entries, exits, risk, params) with validation
- [x] Semantic versioning per strategy; every edit creates a version row
- [x] Backtest runs link to a strategy version, never a mutable strategy

- [x] Wire the schema's `management` and `risk` sections into the JSON runner (validated today, not enforced)
- [x] Pin versions on optimizer/farm sweep backtests too (only the two API entry points pin today)

## Phase 3 — Built-in agent 🤖
- [x] OpenRouter OAuth PKCE "Connect account" flow (no pasted keys)
- [x] Proxy all model calls through the app: per-user rate limit, audit log, abuse cutoff
- [x] Default model `openrouter/free`; contextual nudge to `deepseek/deepseek-v4-flash-vision-exp` at backtest-review time
- [x] System prompt: opinionated X-phase strategy builder (setup → trigger → entry → management → exit → review), one phase at a time
- [x] Agent tools: backtest, review results, compare versions, "share this strategy?" suggestion
- [x] MCP client support: TradingView MCP + pluggable MCP servers config
- [x] Background 24/7 backtest worker on the user's own key with backoff on 429

## Phase 3.5 — Live arena: split-test challengers against the champion ⚔️
Several strategies (or versions of one) run in paper at the same time, on the same live tape, each with its own paper account, against the current champion. Contests on the hub reuse this with a frozen version.
- [x] `arena_seats` table: id, label, strategy_plugin_version_id (or built-in strategy key + params), paper cash, status, started_at, stopped_at; one seat is flagged `champion`
- [x] Multi-seat paper runner: each cycle evaluates every active seat against the same ProductStats snapshot; fills, positions and PnL keyed by seat (positions/fills get a nullable `arena_seat_id`)
- [x] Scoreboard API + Arena page rewrite: live PnL, drawdown, win rate, trade count per seat vs champion, with a "promote to champion" button and "retire seat"
- [x] Agent tools: `start_arena_seat` (from a version), `arena_scoreboard`, and the agent suggests an arena run after a promising backtest
- [x] Tests: two seats on one canned tape produce independent ledgers; promote swaps the champion flag and nothing else

## Phase 4 — Community hub 🏟️ (hosted by us; the desk is single-user and talks to it)
The open-source desk stays one user per install. Everything social lives on the hosted hub (`shoemoneyx-hub`, separate private app). The desk ships a hub client.

**Desk side (this repo)**
- [x] Strategy sync: pull community strategies from the public `shoemoneyx-strategies` git repo (no account needed) and import them as JSON strategy plugins — `App\Strategies\Sync\StrategySync`, `/api/strategies/sync/*`, `artisan strategies:sync`, builder UI, `sync_strategies` agent tool. See `docs/STRATEGY_SYNC.md`.
- [x] `docs/HUB_API.md`: the contract both sides build to (register via agent, publish version, browse/search archive, comments, follows, contests, paper-account stream)
- [x] Hub client (`app/Hub/HubClient`) + `HUB_URL`/`HUB_TOKEN` config; "Register with the community" via the agent creates the hub account and stores the token
- [x] Agent tool `publish_strategy` → hub archive, with semver + changelog; `suggest_share` becomes real
- [x] Archive browser page in the desk (typeahead search over the hub API: exchange, timeframe, asset, win rate, drawdown, author, tags), one-click import of a published version
- [x] Contest entry from the desk: pick a frozen version, run it in an arena seat, report fills + equity to the hub every minute (paper stays local)
- [x] Tests with a fake hub (Http::fake) for every client call

**Hub side (shoemoneyx-hub, private)** — deployed at https://hub.shoemoneyx.com (EC2 t3.small i-0e625f404b5fcf07e, ~$18/mo, `ops/smoke.sh` green); code at https://git.shoemoney.ai/shoemoney/shoemoneyx-hub, 46 tests, `mode: reported|hosted` contests
- [x] Users, profiles, follow, API tokens issued to desks
- [x] Strategy archive with versions, comments, search index, activity feed
- [x] Contests: entry, leaderboard, frozen version, settlement
- [x] Tests for every feature; `artisan test` green before merge

## Phase 5 — "Host it for you" ☁️
- [x] ~~v2 hosted exchange~~ DROPPED 2026-09-11: no shoemoneyx-owned infra touches user trades (liability). Contests stay on reported fills. Reserved routes in `docs/HUB_API.md` stay unimplemented.
- [x] Per-customer locked-down box image (AWS AMI first), only inbound HTTPS, outbound to exchange + OpenRouter — `ops/image/desk.pkr.hcl`, boot-tested by `ops/image/test-boot.sh`, see `docs/HOSTED_IMAGE.md`
- [x] Onboarding: paste OpenRouter key → paper trading in minutes
- [x] Billing: no hub billing. Revenue is the marked-up paid AMI listing on AWS Marketplace (user picks the paid image; 20% listing fee accepted). Decided 2026-09-11.
- [x] Docker-first distribution (v0.1.0 published 2026-09-11, ghcr.io/shoemoney/shoemoneyx + shoemoneyx-nginx, amd64+arm64): full-stack `docker compose up` (web/nginx/desk/queue/schedule/reverb/feeder + mariadb + redis), `docker/up.sh` generates secrets once, CI on tag `vX.Y.Z` publishes multi-arch images to ghcr.io (FA Pro decrypted from `ops/ci/fa-pro.tar.gz.enc` with the `FA_PRO_KEY` secret)
- [x] AMI runs the published Docker image (Ubuntu + docker + compose + `docker/up.sh` at first boot) so customers update with `docker compose pull`; replaces the bare-metal provision.sh. v0.1.2 = ami-0952211a3644f3e1b, boot-tested 2026-09-12 (egress policy re-enforced in DOCKER-USER every boot)
- [x] Image maintenance/update pipeline: `ops/image/release.sh <version>` (idempotent: skips an existing AMI, skips a recorded boot test, resumes a change set) rebuilds from the tagged image, `test-boot.sh` proves it, `ops/image/releases.json` records it, and a Marketplace change set adds the version once `ops/image/marketplace.env` has a product id. First record: 0.1.2 on 2026-09-12.
- [ ] Marketplace listing: seller registration (W-9, US bank, public profile) + first AMI product in the Management Portal are Jeremy's; then `MARKETPLACE_PRODUCT_ID` into `ops/image/marketplace.env` and `release.sh` publishes every later version. First submission should use Intent VALIDATE (OperatingSystemName=UBUNTU is unverified against AWS's enum).
- [x] Marketplace pricing (decided 2026-09-12): hourly, no free trial, no monthly/annual at launch; supported types t3.small (recommended), t3.medium, t3.large; software fee = 1.67x the EC2 rate so net after AWS's 20% is half the buyer's bill: t3.small $0.0347/h, t3.medium $0.0693/h, t3.large $0.1387/h. Annual prepay at 10x the monthly equivalent (2 months free) for buyers who want price security. Hosted/supported = private offer, still in the buyer's account.
- [x] Non-technical buyer path: first login without SSH (bootstrap MASTER_PASSWORD = EC2 instance ID, hint on /login, onboarding refuses an empty password on exposed images). Shipped in v0.1.2.
- [x] No hosted tier at all. Users run the paid AMI in their own AWS account with their own keys (liability decision 2026-09-11).

## Open questions ❓
- Which exchanges get native adapters after Coinbase (Binance, Kraken, Bybit?)
