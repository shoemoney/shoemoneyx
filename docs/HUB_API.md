# Hub API contract 🏟️

The desk is single-user. Everything social lives on the hosted hub (`shoemoneyx-hub`). This file is the contract both sides build to. The desk ships a client (`app/Hub/HubClient`); the hub implements these routes. Change this file first, then both sides.

Base URL: `HUB_URL` (default `https://hub.shoemoneyx.com`). All routes are under `/api/v1`. JSON in, JSON out. Timestamps are ISO 8601 UTC. Versions are semver strings.

## Auth

- Registration returns a **desk token**. The desk stores it encrypted and sends `Authorization: Bearer <token>` on every call after that.
- One account can have many desks (laptop, hosted box). Each desk registers its own token under the same account by logging in once with email + password, or by pasting a one-time link code from the hub website.
- Public reads (archive search, strategy pages, leaderboards) need no token.

## Errors

```json
{ "error": { "code": "rate_limited", "message": "…", "retry_after": 30 } }
```

Codes: `unauthenticated`, `forbidden`, `not_found`, `validation` (with `fields`), `rate_limited`, `conflict`, `contest_closed`.

## Accounts

| Method | Path | Body | Returns |
|---|---|---|---|
| POST | `/register` | `{email, handle, password, desk_name}` | `{user: {id, handle}, token}` |
| POST | `/login` | `{email, password, desk_name}` | `{user, token}` |
| POST | `/link` | `{code, desk_name}` (one-time code shown on the hub site) | `{user, token}` |
| GET | `/me` | | `{user: {id, handle, bio, avatar_url, followers, following, published}, desk: {id, name, created_at}}` |
| PATCH | `/me` | `{handle?, bio?}` | `{user}` |
| DELETE | `/me/desks/{id}` | | `204` |

Handles: `^[a-z0-9_]{3,24}$`, unique.

## Archive

A published strategy is a **strategy** with **versions**. The desk pushes one version at a time. Definitions follow `docs/STRATEGY_SCHEMA.md`.

| Method | Path | Body / Query | Returns |
|---|---|---|---|
| POST | `/strategies` | `{slug, name, description, tags[], version, changelog?, definition, backtest?: {days, stats}}` | `{strategy: {id, slug, url}, version: {id, version}}` |
| POST | `/strategies/{slug}/versions` | `{version, changelog?, definition, backtest?}` | `{version}` (409 if version exists) |
| GET | `/strategies/{slug}` | | `{strategy, author, current_version, versions: [{version, changelog, created_at, stats}], stats: {stars, imports, comments}}` |
| GET | `/strategies/{slug}/versions/{version}` | | `{version, definition}` |
| GET | `/strategies/search` | `q, exchange, timeframe, asset, min_win_rate, max_drawdown, author, tag, sort (new\|stars\|imports\|win_rate), page` | `{results: [{slug, name, author, current_version, tags, timeframe, assets, stats: {win_rate, drawdown, trades, days}}], total, page}` |
| GET | `/strategies/typeahead` | `q` (min 2 chars) | `{results: [{slug, name, author}]}` max 10 |
| POST | `/strategies/{slug}/star` / DELETE | | `{stars}` |
| POST | `/strategies/{slug}/import` | | `{ok}` (counts an import; the desk then GETs the version) |
| DELETE | `/strategies/{slug}` | | `204` (author only; versions become unlisted, never deleted, contests may reference them) |

`backtest.stats` shape: `{return_pct, win_rate, trades, max_drawdown_pct, profit_factor, days, exchange, timeframe, assets[]}`. The hub stores it as author-reported and labels it so.

## Social

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/users/{handle}` | | `{user, strategies: [...], contests: [...]}` |
| POST | `/users/{handle}/follow` / DELETE | | `{following: bool}` |
| GET | `/feed` | `page` | `{items: [{type: published\|new_version\|contest_result\|comment, actor, subject, at}]}` |
| GET | `/strategies/{slug}/comments` | `page` | `{comments: [{id, author, body, version?, created_at, replies}]}` |
| POST | `/strategies/{slug}/comments` | `{body, version?, reply_to?}` | `{comment}` |
| DELETE | `/comments/{id}` | | `204` (author or strategy owner) |

Body max 4000 chars, Markdown, rendered server-side with a strict allowlist.

## Contests

A contest freezes a strategy version per entrant. **Paper trading stays local**: the desk runs the frozen version in an arena seat with its own paper account and **reports** fills and equity to the hub. The hub ranks entrants by reported equity. The hub trusts desks for now; anti-cheat (signed fills, replay against the hub's tape) is a v2 item, see below.

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/contests` | `state (upcoming\|live\|settled)` | `{contests: [{id, slug, name, exchange, assets[], starts_at, ends_at, starting_cash, entrants, rules}]}` |
| GET | `/contests/{slug}` | | `{contest, leaderboard: [{rank, handle, equity, return_pct, drawdown_pct, trades}], me?}` |
| POST | `/contests/{slug}/enter` | `{strategy_slug, version}` | `{entry: {id, frozen_version_id}}` (409 after `starts_at`) |
| DELETE | `/contests/{slug}/enter` | | `204` (before start only) |
| POST | `/contests/{slug}/fills` | `{fills: [{client_id, product_id, side (buy\|sell), size, price, fee_usd, at, position_id?, pnl_usd?}]}` | `{accepted, duplicates}` |
| POST | `/contests/{slug}/snapshots` | `{equity, cash, open_positions: [{product_id, side, size, entry_price, mark}], at}` | `{ok}` (max one per minute per entry) |
| GET | `/contests/{slug}/account` | | `{snapshot: {equity, cash, open_positions, at}, fills: [...last 100]}` |

Fills are idempotent on `client_id` (the desk's fill uuid). The desk reports every minute while an entry is live. Settlement freezes the leaderboard at `ends_at`.

## v2: hosted exchange (not built yet)

Later the hub may run its own tape and fill engine so contests are refereed centrally and desks without a feeder can paper trade on the hub. Reserved routes, unimplemented: `POST /contests/{slug}/orders`, `GET /contests/{slug}/tape/{product_id}`, and `/paper/{exchange}/*` (orders, account, tape, reset). Do not build clients against these until this section moves above the line.

## Rate limits

Per token: 120 requests/minute general, 60 order batches/minute per contest entry, 10 publishes/hour. Responses carry `X-RateLimit-Remaining`.

## Versioning

`/api/v1`. Additive changes don't bump. Removing or renaming a field bumps to `/api/v2` and the old one stays for six months.

## Desk integration

How the desk itself (`app/Hub/*`) wires up against everything above.

**Client.** `App\Hub\HubClient` is the only thing that calls the hub, one typed method per route in this file, all through `Illuminate\Support\Facades\Http` so `Http::fake()` covers it in tests. Base URL is `config('hub.url')` (`HUB_URL` env, default `https://hub.shoemoneyx.com`). It sends `Authorization: Bearer <token>` whenever `App\Hub\HubConnection::active()` finds a non-revoked row, and no header at all otherwise — public reads work unauthenticated. On a `rate_limited` error it sleeps `min(retry_after, 30)` once and retries once; any other error becomes `App\Hub\HubException` (`code`, `message`, `retryAfter`).

**Connection storage.** `hub_connections` (`App\Hub\HubConnection`): `user_handle`, `desk_id`, `token` (encrypted), `connected_at`, `revoked_at`. Single-user desk, so `HubConnection::active()` — latest row with no `revoked_at` — is "this desk's" connection; nothing filters by a local user id.

**Desk-side proxy routes** (`routes/api.php`, all inside the existing `DeskToken` group). These are what the desk's own Vue pages call; each is a thin wrapper that calls the matching `HubClient` method:

| Verb | Path | Controller@method | Hub route it proxies |
|---|---|---|---|
| POST | `/api/hub/register` | `HubAccountController@register` | `POST /register` |
| POST | `/api/hub/login` | `HubAccountController@login` | `POST /login` |
| POST | `/api/hub/link` | `HubAccountController@link` | `POST /link` |
| GET | `/api/hub/status` | `HubAccountController@status` | — (local: `HubClient::connected()` + `HubConnection::active()`) |
| POST | `/api/hub/disconnect` | `HubAccountController@disconnect` | — (local: revokes the active `HubConnection`) |
| POST | `/api/strategy-plugins/{plugin}/publish` | `StrategyPluginController@publish` | `POST /strategies` or `POST /strategies/{slug}/versions`, via `App\Hub\Publisher` |
| GET | `/api/hub/strategies/search` | `HubArchiveController@search` | `GET /strategies/search` |
| GET | `/api/hub/strategies/typeahead` | `HubArchiveController@typeahead` | `GET /strategies/typeahead` |
| GET | `/api/hub/strategies/{slug}` | `HubArchiveController@show` | `GET /strategies/{slug}` |
| GET | `/api/hub/strategies/{slug}/versions/{version}` | `HubArchiveController@version` | `GET /strategies/{slug}/versions/{version}` |
| GET | `/api/hub/strategies/{slug}/comments` | `HubArchiveController@comments` | `GET /strategies/{slug}/comments` |
| POST | `/api/hub/import` | `HubArchiveController@import` | `POST /strategies/{slug}/import` + `GET /strategies/{slug}` + `GET /strategies/{slug}/versions/{version}`, then creates/updates a local `StrategyPlugin` |
| GET | `/api/hub/contests` | `HubContestController@index` | `GET /contests` |
| GET | `/api/hub/contests/{slug}` | `HubContestController@show` | `GET /contests/{slug}` |
| POST | `/api/hub/contests/{slug}/enter` | `HubContestController@enter` | `GET /contests/{slug}` + `POST /contests/{slug}/enter`, then creates an arena seat and a local `contest_entries` row |
| DELETE | `/api/hub/contests/{slug}/enter` | `HubContestController@withdraw` | `DELETE /contests/{slug}/enter`, then retires the seat and marks matching `contest_entries` rows `withdrawn` |

**Publishing.** `App\Hub\Publisher::publish(StrategyPlugin $plugin, ?string $version, ?string $changelog, ?int $backtestId)` is the one place "first publish creates the strategy, later publishes push a version" lives — both `StrategyPluginController::publish()` and the `publish_strategy` agent tool call it. It tracks state with two columns: `strategy_plugins.hub_slug` (null until the first successful publish) and `strategy_plugin_versions.published_at`. When `include_backtest` is given it attaches a best-effort `backtest` payload built from `Backtest::stats` (`total_return_pct` → the contract's `return_pct`, `win_rate`, `trades`, `max_drawdown_pct`, `profit_factor` pass through, `days` from `from`/`to`, `assets` from `products`) — only fields it can honestly derive, nothing fabricated.

**Agent tools** (`app/Ai/Tools/`, registered in `ToolRegistry::withDefaults()`): `hub_register` (never echoes the password or token back), `hub_status`, `publish_strategy`. `suggest_share`'s result now carries `action: 'publish'` alongside `plugin_id`/`version`, which is what the Strategy Builder's share-card "Publish" button reads to call `POST /api/strategy-plugins/{id}/publish`.

**Archive import.** `HubArchiveController::import()` validates the fetched definition the same way `StrategyPluginController::store()` does (`StrategySchemaValidator` or `JsonPluginValidator` depending on `schema_version`), then reuses a plugin already linked by `hub_slug` if one exists, or creates one keyed off the hub slug (falling back to `{slug}-hub`, `{slug}-hub-2`, … if that key is already taken locally by an unrelated plugin). It sets `current_version` to the remote version directly (no local re-bump) and writes a version row with changelog `"imported from hub {handle}/{slug}"`.

**Contests.** Paper trading stays local — there is no executor that routes orders to the hub. `HubContestController::enter()` fetches the contest (for its `starting_cash`), calls `POST /contests/{slug}/enter`, then creates an ordinary `App\Models\ArenaSeat` for the chosen plugin version (same model, same table, same `arena:cycle`/`PaperExecutor` path every other seat trades through — nothing here touches `app/Desk/Arena/*` or `Desk.php`) and a `contest_entries` row (`App\Hub\ContestEntry`: `contest_slug`, `plugin_id`, `version_id`, `entry_id`, `arena_seat_id`, `state` `live|withdrawn|settled`, plus `last_reported_fill_id`/`last_snapshot_at`). `withdraw()` retires that seat (`status = 'retired'`) alongside marking the entry `withdrawn`.

**Reporting.** `App\Hub\ContestReporter::report(ContestEntry $entry)` is the only thing that talks to the hub about a contest after entry — it never reads cash/positions back, it only pushes. Per live entry: `Fill::where('arena_seat_id', $seat->id)->where('status', 'filled')->where('id', '>', $entry->last_reported_fill_id)` gets the unsent fills, mapped to the contract's shape (`client_id` is synthesized as `"seat{$seatId}-fill{$fillId}"` — stable and unique, so idempotent on retry without needing a real UUID column on the shared `fills` table) and pushed via `HubClient::pushFills()`; `last_reported_fill_id` then advances to the batch's last id. Separately, once `last_snapshot_at` is null or ≥60s old, it computes the seat's cash (`PaperExecutor::withSeat($seat->id)->cash()`) and equity (cash + open positions marked at `MarketData::price()`) — the same math `App\Desk\Arena\ArenaScoreboard` uses for the live scoreboard — and pushes it via `HubClient::pushSnapshot()`. Scheduled every minute for every `live` entry by `php artisan hub:report-contests` (`routes/console.php`). A `HubException` from either push is logged and swallowed per-entry so one entry's hub error doesn't stop the rest of that minute's run; the bookmarks only advance on success, so the next run retries whatever didn't land.
