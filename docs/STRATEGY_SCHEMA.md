# Strategy schema — `schema_version: 1`

The formal, sectioned strategy-plugin schema. Every plugin saved before this schema
existed used the flat shape in `docs/STRATEGY_PLUGIN.md` (`scan`/`vet`/`size`/`risk`,
no `schema_version` key) — those still run unchanged: `App\Desk\Strategies\SchemaMigrator`
maps them forward to this shape on every read, so nothing had to be rewritten.

## Complete example

See `resources/strategies/examples/mean-reversion.json` — it validates against
`App\Desk\Strategies\StrategySchemaValidator` as-is.

```json
{
  "schema_version": 1,
  "key": "mean-reversion-h1",
  "base": "custom",
  "suggest": { "products": ["BTC-USD", "ETH-USD"], "days": 30, "cash": 1000 },
  "meta": {
    "name": "Mean Reversion H1",
    "description": "Buys H1-oversold dips in a ranging regime during the US/EU session overlap.",
    "tags": ["mean-reversion", "h1"],
    "timeframe": "1h",
    "assets": ["BTC-USD", "ETH-USD"]
  },
  "params": {
    "rsi_entry": { "type": "number", "default": 35, "min": 10, "max": 50, "step": 1 },
    "allow_shorts": { "type": "bool", "default": false }
  },
  "setup": {
    "rules": [
      { "field": "extra.indicators.atr14", "op": ">", "value": 0 },
      { "field": "time.hour_utc", "op": "between", "value": [12, 20] }
    ]
  },
  "trigger": {
    "max_candidates": 5,
    "rules": [
      { "field": "indicators.rsi14", "op": "<", "value": 35 },
      { "field": "volume_surge_h1", "op": ">", "value": 1.5 }
    ]
  },
  "entry": {
    "side": "long",
    "sizing": { "kelly_fraction": 0.25, "max_pct_book": 6.0 },
    "confirm": [
      { "field": "spread_bps", "op": "<=", "value": 15, "reason": "spread too wide" }
    ]
  },
  "management": {
    "adds": [
      { "trigger": { "field": "position.pnl_pct", "op": ">=", "value": 4 }, "size_pct": 50 }
    ],
    "trailing": { "activate_pct": 6, "trail_pct": 2.5 },
    "partials": [
      { "pct": 5, "fraction": 0.25 },
      { "pct": 10, "fraction": 0.25 }
    ]
  },
  "exit": {
    "stop": { "rules": [{ "field": "volume_ratio_6h", "op": "<", "value": 0.2, "action": "close" }] },
    "take_profit": { "rules": [] },
    "time_stop": { "hours": 48 }
  },
  "risk": { "max_positions": 3, "daily_loss_cap_pct": 5, "leverage_cap": 1 }
}
```

## Top-level keys

| Key | Required | What |
|---|---|---|
| `schema_version` | yes | must be `1` |
| `key` | yes | slug: lowercase letters, numbers, dashes (`^[a-z0-9-]{2,64}$`) |
| `base` | no | which built-in pipeline behavior to inherit: `custom` (default, only option) |
| `suggest` | no | test defaults the builder autopopulates: `{ products[], days, cash }` |
| `meta` | **yes** | `{ name, description?, tags[]?, timeframe?, assets[]? }` |
| `params` | no | declared tunables: `{ key: { type, default, min?, max?, step? } }` |
| `setup` | no | `{ rules[] }` — context that must hold (regime, session hours, volatility) before the universe is scanned |
| `trigger` | no | `{ max_candidates?, rules[] }` — the signal; ANDed with `setup.rules` to filter the scan universe |
| `entry` | **yes** | `{ side?, sizing?, confirm[]? }` — order side, sizing rule, confirmation rules run at vet time |
| `management` | no | `{ adds[], trailing, partials[] }` — position-lifecycle knobs, enforced by `JsonPluginStrategy::risk()` |
| `exit` | no | `{ stop?, take_profit?, time_stop? }` — `stop.rules`/`take_profit.rules` fire a close the same way legacy `risk.rules` did |
| `risk` | no | `{ max_positions?, daily_loss_cap_pct?, leverage_cap? }` — portfolio-level caps, enforced by `JsonPluginStrategy::size()`/`risk()` |

`meta` and `entry` are the only required sections — every other section is optional
and defaults to inert (no filters added, no caps enforced).

### `params`

Each entry is `{ type: "number"|"bool"|"string", default, min?, max?, step? }`. `min`/
`max`/`step` only apply to `type: "number"`. These are declared tunables for a future
builder UI (sliders, toggles) — v1 does not yet substitute them into rule values.

### `setup` and `trigger`

Both hold `{ rules[] }`; `JsonPluginStrategy::scan()` ANDs `setup.rules` with
`trigger.rules` — a coin must pass every rule in both to be scanned. `trigger.max_candidates`
caps how many pass through to ranking (same role as legacy `scan.max_candidates`).

### `entry`

- `side`: `"long"` or `"short"` (declarative; the runner does not yet size shorts differently).
- `sizing`: `{ kelly_fraction?, max_pct_book? }`, same semantics as legacy `size`.
- `confirm`: rules checked at vet time — the first rule whose field is present and fails
  rejects the candidate with its `reason` (a rule on missing data is skipped, not rejected).

### `management`

Checked by `JsonPluginStrategy::risk()`, in this order, after `exit.stop.rules` and before the
generic base-strategy rails (hard stop, base trailing, max hold) it falls back to when nothing
here fires:

- `trailing`: `{ activate_pct, trail_pct }`. Once the position's peak `position.pnl_pct` reaches
  `activate_pct`, a close fires the moment `pnl_pct` gives back `trail_pct` points from that peak
  (`"management.trailing"`). `trail_pct` is required; a `trailing: null` or omitted section leaves
  it off (the generic `risk.trail_activate_pct`/`risk.trail_giveback_pct` rails still apply).
- `partials[]`: `{ pct, fraction }[]`, checked **in array order** — rung `i` is due once
  `position.trims_count == i`, and fires a `TRIM` of `fraction` (0-1) of the current quantity the
  moment `position.pnl_pct >= pct` (rule name `"management.partials.<i>"`). Each rung fires at
  most once; `trims_count` only advances when the trim actually fills.
- `adds[]`: `{ trigger: {field, op, value}, size_pct }[]`, also checked in array order — rung `i`
  is due once `position.adds_count == i`. `trigger` is one rule (the same `{field, op, value}`
  grammar as everywhere else; `position.*` fields are allowed here). When it fires, an `ADD` buys
  `size_pct` percent of the position's *initial* cost basis (`position.initial_cost_usd`, set once
  on the opening fill and never touched by a later add — never the current, already-grown
  `entry_usd`). `adds.length` is the de facto max-adds cap: once `adds_count` reaches it there is
  no rung left to check. Rule name `"management.adds.<i>"`.

### `exit`

- `stop.rules` / `take_profit.rules`: each rule may carry `"action": "close"` (the only
  value in v1, same as legacy `risk.rules`). `stop.rules` is what `JsonPluginStrategy::risk()`
  evaluates against open positions today (checked before `management`, after `risk.daily_loss_cap_pct`);
  `take_profit` and `time_stop` are validated but not yet wired into the runner.

### `risk`

Enforced by `JsonPluginStrategy::size()` (new entries) and `JsonPluginStrategy::risk()` (open
positions):

- `max_positions`: `size()` returns a zero `SizeDecision` for a brand-new entry (not an add to an
  already-open position) once `count(openPositions) >= max_positions`, scoped to this mode/seat
  the same way the generic `size.max_open_positions` capacity check already is.
- `daily_loss_cap_pct`: once today's realised PnL (positions closed since UTC midnight) plus
  unrealised PnL (every currently open position, marked at its last known price) is
  `<= -daily_loss_cap_pct` percent of the first bank snapshot taken today, `risk()` closes every
  open position (rule `"risk.daily_loss_cap_pct"`) and `size()` refuses every new entry until the
  next UTC day. **Live/paper only** — a backtest never persists positions or bank snapshots to the
  database (`Backtester` keeps everything in memory), so this cap is never enforced inside a
  backtest.
- `leverage_cap`: `size()` clamps a ticket so total exposure (every open position's notional plus
  this new ticket) never exceeds `leverage_cap x equity`; a ticket that would leave no room after
  clamping (below `size.min_ticket_usd`) returns zero instead.

## Rules

A rule is `{ "field", "op", "value" }`, plus `reason` (entry.confirm) or `action` (exit).

- `op` is one of `<`, `<=`, `>`, `>=`, `==`, `!=`, `between`, `in`, `not_in`.
  - `between`: `value` is `[min, max]`, inclusive.
  - `in` / `not_in`: `value` is an array; membership test (loose `==` per element).
- `value` is a scalar/null for the six comparison ops, an array for `between`/`in`/`not_in`.
- `field` is a dot path. Allowed roots:
  - any `ProductStats` JSON key (`price`, `spread_bps`, `volume_surge_h1`, …),
  - `indicators.*` (shorthand for `extra.indicators.*`: `rsi14`, `ema9`, `ema21`, `ema50`,
    `atr14`, `macd`, `signal`, `hist`),
  - `time.hour_utc` (0-23, UTC) / `time.weekday` (0=Sunday … 6=Saturday) — session-hour and
    day-of-week gates,
  - `position.*` in `exit`/`management` only (`position.pnl_pct`, `position.hold_hours`).
- A rule whose field is missing/null on the row **never fires** (fail-closed everywhere).

## Legacy shape and migration

`App\Desk\Strategies\SchemaMigrator::migrate()` maps a legacy definition (no
`schema_version` key) into this shape: `scan.filters` → `trigger.rules`, `scan.max_candidates`
→ `trigger.max_candidates`, `vet.rules` → `entry.confirm`, `size` → `entry.sizing`,
`risk.rules` → `exit.stop.rules`. `JsonPluginStrategy` always executes against the migrated
shape, so a plugin saved under the old flat schema keeps running byte-for-byte the same.
`SchemaMigrator::toLegacyView()` is the inverse — the Markdown/Pine exporters use it so they
keep reading the flat field names regardless of which shape a plugin was authored in.

## Validation

`App\Desk\Strategies\StrategySchemaValidator::validate($definition)` returns
`{ valid: bool, errors: [{ path, message }] }` — one entry per structural problem, `path`
pointing at the exact offending key (e.g. `entry.confirm[0].op`). The legacy flat shape is
still validated by `App\Desk\Strategies\JsonPluginValidator` (string errors, no `path`) —
`StrategyPluginController` picks the validator by whether `schema_version` is present.

## Versions

Every save (`POST /api/strategy-plugins`) writes a new immutable row to
`strategy_plugin_versions` (semver `bump`, default `patch`). See `docs/STRATEGY_PLUGIN.md`
for the versioning and backtest-pin API.
