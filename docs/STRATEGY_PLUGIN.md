# Strategy plugin (JSON) — v1 schema

A strategy plugin is a JSON file that describes **full strategy logic** without writing PHP.
v1 is **validate + save only**: the app checks the file, stores it, and shows it in the
Strategy Builder. Running plugins (backtest / paper) comes later.

## Minimal example

```json
{
  "key": "rsi-dip",
  "name": "RSI Dip",
  "description": "Buys H1-oversold dips with a volume surge, tight spread only.",
  "version": 1,
  "base": "custom",
  "params": {
    "scan.max_candidates": 5
  },
  "scan": {
    "max_candidates": 5,
    "filters": [
      { "field": "indicators.rsi14", "op": "<", "value": 35 },
      { "field": "volume_surge_h1", "op": ">", "value": 1.5 }
    ]
  },
  "vet": {
    "rules": [
      { "field": "spread_bps", "op": "<=", "value": 15, "reason": "spread too wide" }
    ]
  },
  "size": {
    "kelly_fraction": 0.25,
    "max_pct_book": 6.0
  },
  "risk": {
    "rules": [
      { "field": "volume_ratio_6h", "op": "<", "value": 0.2, "action": "close" }
    ]
  }
}
```

## Top-level keys

| Key | Required | What |
|---|---|---|
| `key` | yes | slug: lowercase letters, numbers, dashes (`^[a-z0-9-]{2,64}$`) |
| `name` | yes | human name, max 120 chars |
| `description` | no | max 2000 chars |
| `version` | yes | integer, must be `1` |
| `base` | no | which built-in pipeline behavior to inherit: `custom` (default, and currently the only option) |
| `suggest` | no | suggested test options the builder autopopulates: `{ products[], days, cash }` |
| `params` | no | flat tunable overrides, same keys as `config/desk.php` / strategy `defaults()` (e.g. `scan.max_candidates`) |
| `scan` | no | `{ max_candidates?, filters[] }` — coins must pass ALL filters to be ranked |
| `vet` | no | `{ rules[] }` — first failing rule rejects the candidate with its `reason` |
| `size` | no | `{ kelly_fraction?, max_pct_book? }` — both 0 < x, fractions of capital/book |
| `risk` | no | `{ rules[] }` — first matching rule fires its `action` (`close`, default) |

## Rules

A rule is `{ "field", "op", "value" }`, plus `reason` (vet) or `action` (risk).

- `op` is one of `<`, `<=`, `>`, `>=`, `==`, `!=`.
- `value` is a number, string, boolean, or null.
- `field` is a dot path. Allowed roots:
  - any `ProductStats` JSON key (`price`, `spread_bps`, `volume_surge_h1`,
    `volume_ratio_6h`, `price_change_h1_pct`, `buys_h1`, `buy_sell_ratio_h1`, …),
  - `indicators.*` (shorthand for `extra.indicators.*`: `rsi14`, `ema9`, `ema21`,
    `ema50`, `atr14`, `macd`, `signal`, `hist`),
  - `position.*` in `risk` only (`position.pnl_pct`, `position.hold_hours`).
- A rule whose field is missing/null on the row **does not fire** (fail-closed
  everywhere: scan filters exclude the row, vet skips the rule, risk-close
  rules don't fire).

## Sharing

Export the JSON from the Strategy Builder and open a PR against the public
strategies repo. Winners get reviewed, backtested by maintainers, and merged.

## Versions

The formal `schema_version: 1` shape (`docs/STRATEGY_SCHEMA.md`) is versioned:
every `POST /api/strategy-plugins` writes a new immutable row to
`strategy_plugin_versions` rather than overwriting history. `strategy_plugins.current_version`
tracks the latest.

- `POST /api/strategy-plugins` — body may carry `bump: major|minor|patch` (default `patch`)
  and `changelog`. A brand-new plugin always starts at `1.0.0` regardless of `bump`.
- `GET /api/strategy-plugins/{id}/versions` — list, newest first.
- `GET /api/strategy-plugins/{id}/versions/{version}` — one version's full definition.
- `POST /api/strategy-plugins/{id}/versions/{version}/restore` — creates a **new** version
  carrying that old definition forward (never rewrites history), applying the same `bump`.
- `GET /api/strategy-plugins/{id}/versions/{a}/diff/{b}` — `{ added[], removed[], changed[] }`
  by dot path between two versions.

Versions are write-once: there is no update or delete route on `strategy_plugin_versions`.

## Backtests pin a version

A backtest that runs strategy `json` always resolves its definition from a
`strategy_plugin_versions` row, never the mutable `strategy_plugins` row: `POST
/api/strategy-plugins/{id}/backtest` and the generic `POST /api/backtests` (when `params`
carries `json.plugin_key`) both resolve the plugin's `current_version` at creation time,
record it on `backtests.strategy_plugin_version_id`, and every backtest response carries
`strategy_version` (the pinned semver string, or `null` for a non-plugin run). Editing the
plugin afterward creates a new version — it never changes what an already-created backtest
runs, even on re-run.
