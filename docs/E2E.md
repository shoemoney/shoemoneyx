# E2E journeys

Four Playwright scripts in `tests/e2e/*.mjs` drive a real, running desk through a browser —
onboarding, login, the Strategy Builder, the agent, and the chart — and assert on literal
outcomes read back through the app's own API and DOM, never just "the page didn't 500". Each
script is a standalone Node ESM file (`node tests/e2e/<name>.mjs`), prints `PASS`/`FAIL` per
check, writes `results.json` + screenshots to `E2E_OUT` (default `storage/e2e`), and exits
non-zero if any check failed.

**None of these ever touch the shared LAN MariaDB.** Every journey runs against an isolated
sqlite file on a throwaway desk (fresh onboarding wizard each time), never the fleet database —
see the recipe below.

## The four journeys

### 1. `login-and-ai.mjs` — onboarding, login gate, internal AI, chart auth

Proves a fresh desk's first-run path actually works end to end: the onboarding wizard (master
password → OpenRouter key, server-validated → exchange pick → launch), that a second, fresh
browser session is correctly gated to `/login` once a master password exists (and that a wrong
password is rejected while the right one gets through), that the Strategy Builder's two separate
chats — the stateless SMX assist chat and the tool-calling agent chat — both produce real
model-written replies and the agent conversation persists server-side, and that the chart page's
`/api/udf/*` fetches carry the desk token (no 401s) and the lightweight-charts canvas actually
renders at a real height. Also exercises the marketplace-AMI path (login-before-wizard) when
`E2E_BOOTSTRAP_PASSWORD` is set.

Checks: `AMI desk gates on /login before the wizard` · `login page shows the instance-ID hint` ·
`root redirects to onboarding on a fresh desk` · `master-password step advanced to openrouter
step` · `openrouter step accepted the key (server validated it against OpenRouter)` · `wizard
reports completed=true via /api/onboarding` · `master_password step recorded as done` · `fresh
session is sent to /login once a master password exists` · `wrong password stays on /login with
"Wrong password."` · `correct password leaves /login` · `/api/ai/status is 200 and connected` ·
`builder shows the Connected chip` · `/api/ai/models returns a non-empty free-model list` ·
`assist chat produced a model-written assistant reply` · `agent turn produced a model-written
assistant reply` · `agent conversation persisted (GET by the id the page created is 200)` ·
`chart fetched /api/udf/history with 200` · `no /api/udf request was rejected with 401` · `chart
canvas has real height, not a collapsed 0px container` · `run completed without an unexpected
error`.

### 2. `strategy-v2-lifecycle.mjs` — schema-v2 strategy lifecycle

Proves the whole schema-v2 plugin lifecycle through the Strategy Builder UI: paste
`resources/strategies/examples/smx-pi-take-profit-v2.json` into the JSON editor, Validate it
(asserting the actual API response, not just the badge), Save it (a real `strategy_plugins` +
version row, read back via the API), then trigger a backtest from the page and poll it to a
terminal status. Because the strict momentum gate may legitimately never fire against a short
seeded tape, a 0-trade original run falls back to a relaxed copy of the same strategy to prove
the pipeline can produce trades — either way, the surviving run is asserted to have a trade whose
`rule` starts with `take_profit.` or `stop.` with sane, non-placeholder numeric literals. The
journey also PUTs `perps.whole_contracts=false` through `/api/settings` right after login, as
part of its own contract (a $1,000 backtest can't clear the whole-contract floor otherwise,
regardless of what the desk booted with) — no `.env` overrides required.

Checks: `root redirects to onboarding on a fresh desk` · `master-password step advanced to
openrouter step` · `openrouter step accepted the key (server validated it against OpenRouter)` ·
`wizard reports completed=true via /api/onboarding` · `fresh session is sent to /login once a
master password exists` · `correct password leaves /login` · `desk settings:
perps.whole_contracts forced to false for this backtest` · per fixture (`original`, and
`relaxed` when the strict one produces zero trades): `[label] Validate reports valid:true with
zero errors` · `[label] UI shows the "Valid" badge` · `[label] Save returned a plugin id` ·
`[label] GET plugin: key === '<key>'` · `[label] GET plugin: current_version is a semver string`
· `[label] a plugin version row exists for current_version` · `[label] backtest queued with an
id` · `[label] backtest reached a terminal status` · `[label] backtest produced trades (trades >
0)` · `[label] a trade's rule starts with 'take_profit.' or 'stop.'` · `[label] a trade has sane
numeric literals (entry > 0, exit > 0, finite pnl_pct)`; plus, only on the 0-trade original path,
`original strategy: 0 trades, but backtest completed with status=done and a non-null
ending_equity` · `run completed without an unexpected error`.

### 3. `agent-apply-and-backtest.mjs` — agent applies a pasted strategy and backtests it

Proves the tool-calling Strategy Agent (not the stateless assist chat) can take a pasted π v2
strategy plus a plain-English instruction and actually execute it: it pastes
`smx-pi-take-profit-v2.json` into the agent chat with "apply this strategy, then backtest
BTC-USD for 30 days with starting cash 25000", then verifies — all read back through the API,
never just "the page didn't 500" — that the turn called `strategy_json` (saving a real plugin
version with a non-empty changelog) and `run_backtest`, that the backtest finished on BTC-USD
over a ~30-day window having actually consumed candle data, opened real positions and closed real
trades, with at least one trade exiting through the plugin's own `take_profit.`/`stop.` rule
machinery, and that the assistant's reply — both the API body and the rendered DOM, scoped to the
agent chat via its `data-testid="agent-chat"` wrapper — names the real backtest id. A self-check
block runs before the browser even launches, proving the backtest-id text matcher isolates its
two layers (the "backtest #<n>" pattern, and the thousands/decimal noise-stripper) so a false
match inside "$1,000" or "1.0.0" can't slip through unnoticed. $25,000 starting cash (not the
tool's own $1,000 default) is what clears this plugin's whole-contract floor so the engine
actually opens a position — see the file's own header comment for the exact math. This journey
needs `OPENROUTER_MODEL` pinned server-side (see the recipe below) because free-tier model
roulette otherwise produces a model that won't reliably call both tools in one turn.

Checks: `root redirects to onboarding on a fresh desk` · `master-password step advanced to
openrouter step` · `openrouter step accepted the key (server validated it against OpenRouter)` ·
`wizard reports completed=true via /api/onboarding` · `fresh session is sent to /login once a
master password exists` · `correct password leaves /login` · `/api/ai/status is 200 and
connected` · `agent turn completed without an upstream/tool error` · `agent turn called the
strategy_json tool to save the plugin` · `strategy_json tool call actually validated and saved
the intended plugin` · `agent turn called the run_backtest tool` · `agent turn produced a
model-written assistant reply` · `chat transcript (API body) mentions the backtest id from the
tool result` · `chat transcript (rendered DOM) mentions the same backtest id` · `a plugin with
key '<key>' exists` · `GET plugin: current_version is a semver string` · `a version row exists
for current_version, created by the agent this turn` · `version row carries a non-empty
changelog from the agent` · `run_backtest tool result carried a backtest id` · `backtest for the
applied plugin finished without error` · `backtest actually ran on BTC-USD, the product the
instruction named` · `backtest window is ~30 days, the window the instruction named` · `backtest
engine actually consumed candle data` · `backtest is pinned to a version of the applied plugin` ·
`backtest actually opened positions (entry_count > 0)` · `backtest actually closed trades (trades
> 0)` · `at least one trade closed via the plugin's own take_profit./stop. rule` · `run completed
without an unexpected error`.

### 4. `chart-and-positions.mjs` — chart canvas, a real fill marker, backward pagination

Proves the chart page actually renders a real trading history, not a placeholder. It seeds one
known paper BTC-USD position and its opening fill directly through the `Position`/`Fill` models
via `php artisan tinker` (there is no manual-order API route to drive this through the browser),
entry price and time read off the last seeded 1m candle so the fixture always lands inside
whatever window the desk backfilled. It then opens `/chart/BTC-USD` and asserts the
lightweight-charts canvas renders at a real height, the page's own `/api/udf/marks` fetch (not a
stub — the literal payload `markers.setMarkers()` consumes) carries the fixture fill as a 'B'
mark at the exact time, and the position panel shows the exact formatted entry price. It finishes
by drag-panning the chart past its loaded left edge and asserting a second, older
`/api/udf/history` request actually lands (earlier `to`, non-zero bar count) — proving backward
pagination, not just a polling refresh of the right edge.

Checks: `root redirects to onboarding on a fresh desk` · `master-password step advanced to
openrouter step` · `openrouter step accepted the key (server validated it against OpenRouter)` ·
`wizard reports completed=true via /api/onboarding` · `fresh session is sent to /login once a
master password exists` · `correct password leaves /login` · `fixture: a paper BTC-USD position +
fill were seeded via the models` · `chart canvas (.cl-chart-viewport canvas) has real height >
200px` · `the first /api/udf/history request for BTC-USD succeeded with real bars` · `chart's own
/api/udf/marks fetch carries fixture fill #<id> as label 'B' at t=<epoch>` · `position panel shows
status "open"` · `position panel shows the exact entry price "<price>"` · `panning past the left
edge triggered a second /api/udf/history request with an earlier "to" than the first` · `the
older-window request came back with real bars (candle count grew)` · `run completed without an
unexpected error`.

## Fresh-desk recipe

Run each journey against its own throwaway sqlite desk — **never the shared LAN MariaDB.** All
four scripts default to a different port (8010–8013) so they can run concurrently against four
separate desks if you want, or reuse one desk sequentially and reset between journeys (below).

```bash
# 0. One-time: get an OpenRouter key out of the aigate vault (never hardcode or print it)
if [ -f ~/.claude/aigate/env ]; then set -a; . ~/.claude/aigate/env; set +a; fi
T="${AIGATE_TOKEN:-$(ssh -l shoemoney 192.168.1.10 'sudo docker exec aigate env | grep ^AIGATE_TOKEN' | cut -d= -f2-)}"
OPENROUTER_API_KEY=$(curl -s -H "Authorization: Bearer $T" "${AIGATE_URL:-https://aigate.shoemoney.ai}/api/keys/openrouter" \
  | python3 -c 'import sys,json;print(json.load(sys.stdin)["key"])')

# 1. Isolated sqlite desk (repeat per journey with its own $S / port, or reset between journeys — see below)
S=/tmp/e2e-desk; mkdir -p "$S"; : > "$S/e2e.sqlite"
export APP_URL=http://127.0.0.1:8010 DB_CONNECTION=sqlite DB_DATABASE="$S/e2e.sqlite" DB_URL= \
       SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync BROADCAST_CONNECTION=log
# journey 3 (agent-apply-and-backtest.mjs) needs a model pinned that reliably calls both tools
# in one turn — export this before `php artisan serve`, not in the test's own env:
export OPENROUTER_MODEL=nvidia/nemotron-3-ultra-550b-a55b:free   # only needed for journey 3

# 2. Schema + market data (BTC-USD 1H for backtests, 1m for the fill-marker fixture's candle)
php artisan migrate --force
php artisan market:sync-products
php artisan market:backfill BTC-USD --timeframe=1H --days=11
php artisan market:backfill BTC-USD --timeframe=1m --days=9

# 3. Frontend build + server
npm run build
php artisan serve --host=127.0.0.1 --port=8010 &

# 4. Playwright — a global install is fine, point PLAYWRIGHT_PATH at it
export PLAYWRIGHT_PATH=/opt/homebrew/lib/node_modules/playwright
# (first time only) npm i -D playwright && npx playwright install chromium

# 5. Run a journey
E2E_BASE=http://127.0.0.1:8010 E2E_OUT="$S/out" OPENROUTER_API_KEY="$OPENROUTER_API_KEY" \
  node tests/e2e/login-and-ai.mjs
```

Repeat step 5 for `strategy-v2-lifecycle.mjs`, `agent-apply-and-backtest.mjs` (needs
`OPENROUTER_MODEL` set on the server as above), and `chart-and-positions.mjs` (needs `php artisan`
on `$PATH` in the same shell, since it shells out to `php artisan tinker` against
`E2E_BASE`'s own repo checkout to seed its fixture).

### Between-journey reset

Every journey onboards its own fresh desk from scratch (it asserts on the onboarding wizard
itself), so **reset the desk's state between journeys** rather than reusing a completed one:

```bash
php artisan tinker --execute="
  \App\Models\Setting::truncate();
  \App\Models\AiConnection::truncate();
  \App\Models\ExchangeCredential::truncate();
"
php artisan cache:clear
```

Candle data (from `market:sync-products` / `market:backfill`) is left in place across resets —
only the onboarding/auth/settings state needs to go back to zero for the wizard to run again.

### Rule

**E2E never touches the shared LAN MariaDB.** `DB_CONNECTION=sqlite` against a throwaway file
under `/tmp` (or any scratch dir), always — not the fleet Galera cluster, not a shared dev
database. Each journey's fixture data (the seeded onboarding state, the tinker'd position/fill)
lives only in that sqlite file and is disposable.
