# Strategy sync

The desk pulls community strategies from a public git repo,
[`shoemoneyx-strategies`](https://git.shoemoney.ai/shoemoney/shoemoneyx-strategies) —
no account needed, no write access, just plain HTTP(S) GETs against a manifest
and a folder of strategy JSON files. See `App\Strategies\Sync\StrategySync`.

## Repo layout

```
shoemoneyx-strategies/
├── LICENSE
├── README.md
├── build-strategies-manifest.php   (copy of ops/build-strategies-manifest.php)
├── manifest.json                   (generated — do not hand-edit)
└── strategies/
    ├── mean-reversion.json
    └── breakout.json
```

Every file under `strategies/` is one `schema_version: 1` strategy plugin
definition — see `docs/STRATEGY_SCHEMA.md`. A strategy file may carry one
extra top-level key the desk schema doesn't define: `"author"` (a string,
defaults to `"community"` if absent). The desk's schema validators ignore
unknown top-level keys, so this is safe.

## `manifest.json`

```json
{
  "schema_version": 1,
  "generated_at": "2026-09-10T00:00:00+00:00",
  "strategies": [
    {
      "id": "mean-reversion-h1",
      "name": "Mean Reversion H1",
      "version": "1.0.0",
      "file": "strategies/mean-reversion.json",
      "sha256": "…64 hex chars…",
      "tags": ["mean-reversion", "h1"],
      "timeframe": "1h",
      "assets": ["BTC-USD", "ETH-USD"],
      "author": "community",
      "description": "Buys H1-oversold dips in a ranging regime…",
      "min_desk_schema": 1
    }
  ]
}
```

| Field | What |
|---|---|
| `id` | slug, same rules as a strategy `key` (`^[a-z0-9-]{2,64}$`) — this is what the desk stores as `remote_id` and matches on every sync |
| `name` | display name |
| `version` | semver — bumped automatically by the manifest builder, never hand-authored |
| `file` | path to the strategy JSON, relative to the repo root |
| `sha256` | sha256 of the exact bytes at `file`, verified by the desk before it ever parses the JSON |
| `tags`, `timeframe`, `assets` | copied from the strategy's `meta` block |
| `author` | copied from the strategy file's top-level `author`, or `"community"` |
| `description` | copied from `meta.description` |
| `min_desk_schema` | the strategy schema version this file needs; the desk skips an entry whose `min_desk_schema` is newer than it understands (`App\Desk\Strategies\SchemaMigrator::CURRENT_VERSION`) instead of failing the whole sync |

## Contributing a strategy (PR workflow)

1. Fork/branch `shoemoneyx-strategies`.
2. Validate your strategy against a running desk first: paste it into the
   Strategy Builder (`/strategy-plugins/validate`) or run it through the
   Strategy Agent — fix every error before opening a PR.
3. Drop the JSON file under `strategies/`, with a real `meta.name`,
   `meta.description`, `meta.tags`, `meta.timeframe`, `meta.assets`, and
   optionally a top-level `author`.
4. Run the manifest builder from the repo root: `php build-strategies-manifest.php`.
   It rewrites `manifest.json` — commit the result alongside your strategy file.
5. Open a PR. A strategy that changes an existing file bumps its own patch
   version automatically the next time the builder runs; you never hand-edit
   a version number.

## Building the manifest (`ops/build-strategies-manifest.php`)

Standalone PHP, no framework, no dependencies — it's meant to run inside the
strategies repo, not the desk. It reads every `strategies/*.json`, computes
each file's sha256, reads `key`/`meta.*`/`author`/`schema_version` out of the
JSON, and diffs against the manifest.json already on disk to decide each
entry's version: new id → `1.0.0`; unchanged sha256 → same version as before;
changed sha256 → patch bump.

```
php build-strategies-manifest.php                       # repo root == cwd
php ops/build-strategies-manifest.php /path/to/repo      # explicit root
```

## The desk side

- `config/strategies.php`: `source_url` (env `STRATEGIES_SOURCE_URL`) is the
  base URL everything is fetched relative to — it must resolve
  `<source_url>manifest.json` and `<source_url><file>` for each entry.
  Defaults to the Forgejo raw URL for `shoemoneyx-strategies`. A GitHub
  mirror can be pointed at with the env var later; nothing here assumes
  GitHub specifically.
- `check_interval_minutes` (env `STRATEGIES_CHECK_INTERVAL_MINUTES`, default
  360) sets both the `GET /api/strategies/sync/status` cache TTL and the
  scheduled check's cadence (`routes/console.php`, every 6h).
- `App\Strategies\Sync\StrategySync`:
  - `check()` — fetches and verifies the manifest, diffs it against the
    `synced_strategies` table, returns `{new, updated, up_to_date}`. Never
    touches a `StrategyPlugin`; only bookkeeping.
  - `import(string $remoteId)` — fetches the strategy file, verifies its
    sha256 against the manifest, validates it against the desk's schema
    validator, and either creates a new `StrategyPlugin` (first import) or
    adds a new version to the existing one (re-import of an updated remote).
    An updated remote never overwrites a plugin's history — it's always a
    new version, exactly like a manual save from the builder. If the
    plugin's current definition has drifted from what sync last wrote (an
    operator edited it locally), the response carries `local_modified: true`
    — the import still happens, nothing is silently discarded.
  - `importAll()` — imports every strategy `check()` reports as `new`.
- HTTP surface (desk-token protected, same as the rest of `/api`):
  `GET /api/strategies/sync/status`, `POST /api/strategies/sync/check`,
  `POST /api/strategies/sync/import {remote_id}`,
  `POST /api/strategies/sync/import-all`.
- `php artisan strategies:sync [--import]` — same check, prints a summary,
  `--import` also imports every new strategy found.
- Agent tool `sync_strategies` (pass `import: true` to import) — the
  Strategy Agent can offer this from any phase.
- Strategy Builder UI: a "Community strategies" block shows "N new / M
  updated available" with per-strategy Import buttons and an "Import all
  new" shortcut.
