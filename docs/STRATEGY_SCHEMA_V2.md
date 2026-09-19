# Strategy schema — `schema_version: 2`

Status: **validator, formulas and migration implemented; engine pending.** v1
(`docs/STRATEGY_SCHEMA.md`) keeps running unchanged. `SchemaMigrator::v1ToV2()` maps v1
forward to v2 on demand, the same way `migrate()` maps the legacy flat shape to v1 — it is
not called automatically on read yet, since nothing consumes v2 at runtime until phase C
lands the engine (`take_profit` ladder, `reentry`, `stop` anchoring). Until then, a v1
definition stays v1.

## Why v2

v1 can say "sell 25% at +5%" and "close when pnl <= -5". It cannot say the things people
actually want to build:

- **Indicator conditions with parameters.** v1 exposes a fixed 1H bundle (`indicators.rsi14`,
  `ema9/21/50`, `atr14`, `macd`). No RSI(7), no EMA(20) crossing EMA(50), no Bollinger, no VWAP.
- **Named, reusable conditions.** "Re-enter when the entry signal fires again" has to be
  copy-pasted today.
- **A re-entry loop.** Buy back a sold slice on a retrace, sized by a formula, cash most of it out
  the moment it is green after fees, and re-arm the take-profit ladder from the new average.
- **A ladder sized off the original position** (2/4/8/10% of what you bought), not fractions of
  whatever is left, with a reset after every add.
- **A stop that re-anchors** to the new average after every add or re-buy.
- **Sizing as % of equity, a dollar amount, or a bounded formula**, not only Kelly.

v2 adds exactly those. Nothing else. Everything is still `{ field, op, value }` rules inside
typed sections, so a card builder, the built-in agent, and a hand-written file all produce the
same JSON, and the hub can render a "how this works" poster from it without a human.

## Design rules

1. **Cards are typed and dumb.** A section is a fixed set of keys with a fixed meaning. No
   section takes arbitrary code.
2. **Formulas are a tiny grammar**, not a language. Numbers, `+ - * / ( )`, `min max abs`, and a
   closed list of named variables. The validator rejects anything else.
3. **References, not copies.** `signals` are named once and referenced by name from `entry`,
   `reentry`, and `stop`. `"when": "entry"` in `reentry` means "the same signals as entry".
4. **Fail closed.** A rule on a missing value never fires. An unknown field, op, variable, or
   signal name is a validation error, never a runtime guess.
5. **Every number that matters is measured from the average price** (`avg`), which is the
   position's current cost basis after every add. That is what makes "reset on add" and
   "re-anchor on add" free: the state is keyed on `avg`, and `avg` moves.
6. **The acceptance test is the SMX π Take Profit strategy** (worked example below). If v2
   cannot express the whole poster, v2 is not done.

## Complete example: SMX π Take Profit

```json
{
  "schema_version": 2,
  "key": "smx-pi-take-profit",
  "author": "shoemoney",
  "meta": {
    "name": "SMX π Take Profit",
    "description": "Nibble 2/4/8/10% at +0.5/1/1.5/2%, run the rest on a TTP, π re-entry on the dip, cash it out green after fees, 2.5% fail-safe.",
    "tags": ["take-profit", "ladder", "reentry", "smx"],
    "timeframe": "1h",
    "assets": ["BTC-USD", "ETH-USD"]
  },
  "params": {
    "rung_spacing_pct": { "type": "number", "default": 0.5, "min": 0.1, "max": 5, "step": 0.1 },
    "fail_safe_pct":    { "type": "number", "default": 2.5, "min": 0.5, "max": 10, "step": 0.1 }
  },
  "signals": {
    "liquid":   { "all": [ { "field": "volume_h24_usd", "op": ">", "value": 500000 } ] },
    "momentum": { "all": [ { "field": "price_change_h24_pct", "op": ">", "value": 2 },
                           { "field": "volume_surge_h1", "op": ">", "value": 1.5 } ] },
    "not_overbought": { "all": [ { "field": "ind.rsi(14)", "op": "<", "value": 70 } ] }
  },
  "entry": {
    "side": "long",
    "when": ["liquid", "momentum", "not_overbought"],
    "confirm": [ { "field": "spread_bps", "op": "<=", "value": 20, "reason": "spread too wide" } ],
    "size": { "mode": "pct_equity", "value": 5 }
  },
  "take_profit": {
    "from": "avg",
    "ladder": [
      { "at_pct": 0.5, "sell_pct_of_original": 2 },
      { "at_pct": 1.0, "sell_pct_of_original": 4 },
      { "at_pct": 1.5, "sell_pct_of_original": 8 },
      { "at_pct": 2.0, "sell_pct_of_original": 10 }
    ],
    "runner": { "ttp": { "activate_pct": 2.0, "giveback_pct": 1.0 } },
    "reset_on_add": true
  },
  "reentry": {
    "when": "entry",
    "after_rungs": 1,
    "retrace": { "of": "rung_spacing", "min": 0.5, "stay_above_avg": true },
    "size": { "mode": "formula", "expr": "sold_qty * min(1, retrace_pct * pi / spacing_pct)" },
    "min_spacing_x_fees": 3,
    "cash_out": {
      "when": "green_after_fees",
      "sell_pct_of_reentry": 80,
      "remainder": "runner"
    },
    "max_per_position": 3
  },
  "stop": {
    "pct_from_avg": 2.5,
    "anchor": "avg",
    "time_hours": null,
    "rules": []
  },
  "risk": { "max_positions": 3, "daily_loss_cap_pct": 5, "leverage_cap": 1 }
}
```

## Top-level keys

| Key | Required | What |
|---|---|---|
| `schema_version` | yes | must be `2` |
| `key`, `base`, `author`, `suggest`, `meta`, `params` | as v1 | unchanged |
| `signals` | no | named condition groups, referenced by name everywhere else |
| `entry` | **yes** | side, which signals must hold, confirm rules, sizing |
| `adds` | no | v1 `management.adds`, unchanged (rule-triggered pyramiding, sized off initial cost) |
| `take_profit` | no | the ladder, the runner, reset behaviour |
| `reentry` | no | the buy-back loop and its cash-out |
| `stop` | no | fail-safe from the average, time stop, extra close rules |
| `risk` | no | portfolio caps, unchanged from v1 |

`meta` and `entry` are the only required sections. Every other section defaults to inert.

### `signals`

```json
"signals": {
  "<name>": { "all": [rule, ...], "any": [rule, ...] }
}
```

- `<name>` matches `^[a-z][a-z0-9_]{1,31}$`. `entry` is reserved (it is the literal that
  `reentry.when` uses to mean "same as entry").
- `all` must every hold; `any` needs one. Both may be present (ANDed). At least one is required.
- Rules are the v1 grammar plus the v2 fields and ops below.
- A signal referenced nowhere is a validation **warning** (the builder shows it greyed), not an
  error.

### `entry`

| Key | What |
|---|---|
| `side` | `long` or `short` |
| `when` | array of signal names; all must hold for a coin to be a candidate |
| `max_candidates` | cap on how many pass to ranking (v1 `trigger.max_candidates`) |
| `confirm` | rules checked at vet time, first failure rejects with its `reason` (v1 semantics) |
| `size` | a **sizing object**, see below |

### Sizing objects

Used by `entry.size`, `adds[].size`, `reentry.size`.

| `mode` | Keys | Meaning |
|---|---|---|
| `pct_equity` | `value` | percent of current equity (cash + open positions marked) |
| `usd` | `value` | a fixed dollar notional |
| `kelly` | `fraction`, `max_pct_book` | v1 sizing, kept for migration |
| `formula` | `expr` | a bounded expression, see **Formulas** |

Every mode is still clamped by `risk.leverage_cap`, `size.min_ticket_usd`, and available cash.
A size that clamps to below the minimum ticket produces no order.

### `take_profit`

```json
"take_profit": {
  "from": "avg",
  "ladder": [ { "at_pct": 0.5, "sell_pct_of_original": 2 }, ... ],
  "runner": { "ttp": { "activate_pct": 2.0, "giveback_pct": 1.0 } },
  "reset_on_add": true
}
```

- `from`: `avg` (only value in v2). Every rung is `avg × (1 ± at_pct/100)` for long/short.
- `ladder[]`: rungs fire **in order, once each per arming**. `sell_pct_of_original` is a percent
  of the position's quantity **at the moment the ladder was armed** (the fresh entry, or the
  re-arm after an add). This is the difference from v1 `partials.fraction`, which was a fraction
  of whatever was left. `at_pct` must strictly increase. The sum of `sell_pct_of_original` must be
  ≤ 100; what it leaves is the runner.
- `runner.ttp`: trailing take-profit on whatever the ladder did not sell. Arms once peak pnl from
  `avg` reaches `activate_pct`, closes the remainder when pnl gives back `giveback_pct` points
  from the peak. `activate_pct` defaults to the last rung's `at_pct`.
- `reset_on_add`: when `true`, any add (an `adds` rung or a `reentry` buy) that moves `avg`
  re-arms the whole ladder from the new `avg`, with `original` re-based to the current quantity.
  When `false`, rungs already fired stay fired and remaining rungs are re-priced from the new
  `avg`.

Engine state, stored on `position.meta.v2.ladder`:
`{ avg, original_qty, fired: [i, ...], sold: { i: { qty, price } } }`. The ladder is rebuilt
whenever `avg` differs from the stored `avg` (that check is what implements `reset_on_add`).

### `reentry`

```json
"reentry": {
  "when": "entry",
  "after_rungs": 1,
  "retrace": { "of": "rung_spacing", "min": 0.5, "stay_above_avg": true },
  "size": { "mode": "formula", "expr": "sold_qty * min(1, retrace_pct * pi / spacing_pct)" },
  "min_spacing_x_fees": 3,
  "cash_out": { "when": "green_after_fees", "sell_pct_of_reentry": 80, "remainder": "runner" },
  "max_per_position": 3
}
```

| Key | What |
|---|---|
| `when` | `"entry"` (same signals as `entry.when`) or an array of signal names, or `null` for "price only" |
| `after_rungs` | how many ladder rungs must have fired before a re-entry can arm (default 1) |
| `retrace.of` | what the retrace is measured against: `rung_spacing` (the distance between rungs, in %) or `pct` (an absolute % of `avg`) |
| `retrace.min` | the retrace, as a multiple of `of`, that must be given back from the **last fired rung's sale price** toward `avg` before a re-buy arms (0.5 = halfway) |
| `retrace.stay_above_avg` | when `true` (default) a re-buy only fires while price is still on the profitable side of `avg` |
| `size` | a sizing object; in `formula` mode the re-entry variables below are available |
| `min_spacing_x_fees` | a rung only re-arms for re-entry when its spacing ≥ this × the round-trip fee % (default 3; 0 disables) |
| `cash_out.when` | `green_after_fees` (only value in v2): the re-bought lot is in profit after both legs' fees, measured from the lot's own fill price |
| `cash_out.sell_pct_of_reentry` | how much of the re-bought lot to sell at cash-out (default 100) |
| `cash_out.remainder` | `runner` (joins the position's runner) or `ladder` (counts toward the re-armed ladder's original) |
| `max_per_position` | cap on re-entries over the life of a position (default 3) |

Mechanics, in words: a rung fired and sold `sold_qty` at `sale_price`. Price then comes back
toward `avg` by at least `retrace.min × spacing`. A buy of `size` fills at market, booked through
the ordinary add path, which moves `avg`. With `take_profit.reset_on_add`, that re-arms the
ladder. The re-bought lot is tracked separately (`position.meta.v2.reentries[]`), and the
moment it is green after fees, `cash_out.sell_pct_of_reentry` of that lot is sold. Risk out,
margin back, better average kept.

Engine state, `position.meta.v2.reentries[]`:
`{ qty, price, fees_usd, cashed_out: bool, rung: i }`.

### `stop`

```json
"stop": {
  "pct_from_avg": 2.5,
  "anchor": "avg",
  "time_hours": 72,
  "rules": [ { "field": "volume_ratio_6h", "op": "<", "value": 0.2, "action": "close" } ]
}
```

- `pct_from_avg` + `anchor: "avg"`: close the whole position when price is `pct_from_avg` percent
  against the position from its **current** average. Because `avg` moves on every add, so does
  the stop. That is the "resets on re-adds" behaviour, and it needs no extra state.
- `anchor: "entry"` pins the stop to the first fill's price instead (v1 behaviour of a
  `position.pnl_pct` rule is `avg`, so v1 migrates to `avg`).
- `time_hours`: v1 `exit.time_stop.hours`.
- `rules[]`: v1 `exit.stop.rules`, unchanged.

### `adds` and `risk`

Unchanged from v1 `management.adds` and `risk`, lifted to top level. `adds[].size_pct` is kept
and also accepts a sizing object as `adds[].size`.

## Rules in v2

A rule is still `{ field, op, value }`. Two additions.

### Parametric indicator fields

`ind.<name>(<args>)[.<output>]`, computed on `meta.timeframe` by default, with an optional
per-rule `"tf": "15m"` override. The set is closed; the validator knows every name, its arity,
and its outputs.

| Field | Outputs | Notes |
|---|---|---|
| `ind.rsi(n)` | value | |
| `ind.sma(n)`, `ind.ema(n)` | value | |
| `ind.atr(n)` | value, `.pct` (ATR ÷ price × 100) | |
| `ind.adx(n)` | value | |
| `ind.macd(f,s,sig)` | `.macd`, `.signal`, `.hist` | |
| `ind.bb(n,k)` | `.upper`, `.lower`, `.mid`, `.pos` (0 = lower band, 1 = upper) | Bollinger |
| `ind.vwap` | value | session VWAP on the timeframe |
| `ind.obv` | value | |
| `ind.smx` | `.wt1`, `.wt2`, `.wt_cross`, `.rsi_mfi`, `.buy`, `.sell`, `.gold_buy`, `.div_bull`, `.div_bear` | the existing SMX cipher (`App\Services\Indicators\Smx::compute`), today computed nowhere the rule evaluator can see |

v1 fields `indicators.rsi14` etc. remain valid aliases for `ind.rsi(14)` on `1h`.

Today `ProductStatsBuilder` computes one fixed bundle on 1H bars and stores it in
`extra.indicators`. v2 computes `ind.*` lazily per (product, timeframe, indicator, args) through
`DeskContext::bars()` (which the backtester already serves from preloaded arrays), memoised per
bar in a small `IndicatorCache`, so a strategy that never mentions an indicator pays nothing.
The backtester preloads the step timeframe and 1H; phase A extends the preload to every `tf` a
strategy's rules name, discovered at validation time.

### Field-to-field comparison and crosses

`value` may be `{ "field": "..." }` instead of a number, for any op. Two new ops need the
previous bar as well as the current one:

- `crosses_above`: field was ≤ value on the previous bar and is > value now
- `crosses_below`: the mirror

```json
{ "field": "ind.ema(20)", "op": "crosses_above", "value": { "field": "ind.ema(50)" } }
```

`position.*` gains `position.avg`, `position.peak_pct`, `position.rungs_fired`,
`position.reentries`, `position.adds_count`, `position.trims_count`.

## Formulas

Allowed only in a sizing object with `mode: "formula"`.

Grammar: decimal numbers, `+ - * /`, parentheses, unary minus, and the functions `min(a, b)`,
`max(a, b)`, `abs(a)`. Identifiers are from this closed list, and only those marked for the
section are available there:

| Variable | Meaning | entry | adds | reentry |
|---|---|---|---|---|
| `equity` | cash + marked open positions | ✓ | ✓ | ✓ |
| `cash` | free cash | ✓ | ✓ | ✓ |
| `price` | current mark | ✓ | ✓ | ✓ |
| `pi` | 3.14159… | ✓ | ✓ | ✓ |
| `fees_rt_pct` | round-trip fee % (taker in + taker out, or maker if the desk is post-only) | ✓ | ✓ | ✓ |
| `avg` | position average | | ✓ | ✓ |
| `position_usd` | position notional at mark | | ✓ | ✓ |
| `initial_cost_usd` | first fill's cost | | ✓ | ✓ |
| `spacing_pct` | ladder rung spacing in % | | | ✓ |
| `retrace_pct` | how far price came back toward `avg` from the last fired rung, in % of `avg` | | | ✓ |
| `sold_qty`, `sold_usd` | the last fired rung's sold quantity / proceeds | | | ✓ |

The result is a **quantity** for `reentry` (units of the asset) and a **dollar notional** for
`entry` and `adds`. Division by zero yields no order. The evaluator is a 60-line
recursive-descent parser with no side effects; the validator parses every formula at save time
and rejects unknown identifiers with the exact offset.

## Evaluation order

`scan`: for each coin, every signal in `entry.when` must hold (all rules, on the coin's stats
and indicator bundle). `vet`: `entry.confirm`. `size`: `entry.size`, clamped by `risk`.

`risk()` per open position, first match wins, in this order:

1. `risk.daily_loss_cap_pct` (live/paper only, as v1)
2. `stop.rules`, then `stop.pct_from_avg`, then `stop.time_hours`
3. `reentry.cash_out` for any re-bought lot that is green after fees
4. `take_profit.ladder`: the next unfired rung, if reached (bar high/low aware in backtests)
5. `reentry`: arm and buy if a rung has fired, the retrace is met, the spacing clears fees, the
   signal (if any) holds, and `max_per_position` is not hit
6. `adds`: the next rung, as v1
7. `take_profit.runner.ttp`
8. base-strategy rails (volume dry-up, unmeasurable, max hold) exactly as v1

One decision per call, as today. Ladder state is rebuilt on any `avg` change before step 4.

## Fees

"Green after fees" uses the desk's configured rates (`desk.fees.taker_rate`,
`desk.fees.maker_rate`, defaults 0.6% / 0.4% on Coinbase Advanced base tier; a strategy may
declare `meta.fees: { taker_pct, maker_pct }` to override for its own backtests). A re-bought
lot at fill `p_in` with quantity `q` is green after fees when
`(price - p_in) × q > fee(p_in × q) + fee(price × q)` for a long, mirrored for a short. The
backtester already charges both legs; this only reads the same numbers.

## Migration v1 → v2

`SchemaMigrator::v1ToV2()`, applied on read so nothing is rewritten on disk:

| v1 | v2 |
|---|---|
| `setup.rules` | `signals.setup.all` |
| `trigger.rules`, `trigger.max_candidates` | `signals.trigger.all`, `entry.max_candidates` |
| `entry.when` | `["setup", "trigger"]` (only the ones present) |
| `entry.sizing { kelly_fraction, max_pct_book }` | `entry.size { mode: "kelly", ... }` |
| `management.partials[] { pct, fraction }` | `take_profit.ladder[] { at_pct: pct, sell_pct_of_original: f_i × Π(1 − f_j, j<i) × 100 }`, `reset_on_add: false` |
| `management.trailing { activate_pct, trail_pct }` | `take_profit.runner.ttp { activate_pct, giveback_pct }` |
| `management.adds` | `adds` |
| `exit.stop.rules`, `exit.time_stop.hours` | `stop.rules`, `stop.time_hours` |
| `exit.take_profit.rules` | `take_profit.rules` (close-only rules, kept for compatibility) |
| `risk` | `risk` |

The partials conversion is exact: a v1 fraction-of-remaining ladder produces the same fills as
the equivalent percent-of-original ladder as long as nothing re-arms it, and `reset_on_add:
false` guarantees that. Every v1 example in `resources/strategies/examples` must round-trip
through the migrator and produce byte-identical backtest fills. That is a test.

## Validation

`StrategySchemaValidator::validate()` dispatches on `schema_version`. v2 adds these checks, each
reported with a `path`:

- every `entry.when` / `reentry.when` / `stop` signal name exists in `signals`
- `ind.*` fields parse, are in the table, have the right arity, and name a real output
- `crosses_*` ops have a `{ field }` value
- ladder `at_pct` strictly increasing, `sell_pct_of_original` sum ≤ 100
- formulas parse and use only variables allowed in that section
- `reentry` requires a `take_profit.ladder` (nothing to re-buy otherwise)
- `params` are substituted into rule values and formulas as `$name` before validation, so a
  formula may say `avg * (1 - $fail_safe_pct / 100)`

### Error messages

Every error is `{path, message}`. Exact wording, by `path` shape:

| Path | Message |
|---|---|
| `schema_version` | `schema_version must be 2` |
| `key` | `key is required: 2-64 chars, lowercase letters, numbers, dashes` |
| `signals.<name>` | `signal name must match ^[a-z][a-z0-9_]{1,31}$ and not be "entry" (reserved)` |
| `signals.<name>` | `signals.<name> requires 'all' and/or 'any'` |
| `entry.when[i]` / `reentry.when[i]` | `unknown signal "<name>"` |
| `entry.size` (or `reentry.size`) | `<path> is required (a sizing object)` |
| `<path>.mode` | `mode must be one of: pct_equity, usd, kelly, formula` |
| `<path>.value` | `value is required and must be a positive number` |
| `<path>.fraction` | `fraction is required and must be a positive number` |
| `<path>.expr` | `expr is required and must be a non-empty string` |
| `<path>.expr` | `unknown parameter reference $<name>` |
| `<path>.expr` | `formula parse error at offset <n>: <parser message>` |
| `<path>.expr` | `unknown variable "<name>" in <section>; allowed: <list>` |
| `take_profit.ladder[i].at_pct` | `at_pct must strictly increase along the ladder` |
| `take_profit.ladder` | `sum of sell_pct_of_original must be <= 100` |
| `reentry` | `reentry requires a non-empty take_profit.ladder (nothing to re-buy otherwise)` |
| `reentry.retrace` | `retrace is required: {of, min, stay_above_avg?}` |
| `stop.anchor` | `anchor must be "avg" or "entry"` |
| `adds[i].size_pct` | `size_pct or size is required` |
| `<rule path>.field` | `field is unknown (stats key, ind.*, indicators.*, time.*, or position.* where allowed)` |
| `<rule path>.field` | `position.* fields are not allowed here` |
| `<rule path>.field` | `unknown indicator "ind.<name>"` |
| `<rule path>.field` | `ind.<name> requires <n> argument(s)` / `takes <n> argument(s), got <m>` |
| `<rule path>.field` | `ind.<name> requires an output, one of: .<a>, .<b>, ...` |
| `<rule path>.field` | `unknown output ".<output>" for ind.<name>` |
| `<rule path>.op` | `op must be one of: <, <=, >, >=, ==, !=, between, in, not_in, crosses_above, crosses_below` |
| `<rule path>.value` | `value must be {field: "..."} for op "<op>"` (crosses_above/crosses_below) |
| `<rule path>.value` | `unknown parameter reference $<name>` |
| `<rule path>.value` | `value must be a scalar, null, or {field: "..."}` |

## The hub renders a poster from this

When a strategy is shared to the hub (`POST /api/v1/strategies`), the hub renders an explanatory
infographic from the definition, in the `smx-infograph` house style, cached per version at
`GET /strategies/{slug}/poster.png` and used as the share image. No human writes it. The mapping
is deterministic because every section is typed:

| Section | Poster card | Text source |
|---|---|---|
| `entry.when` + `signals` | "When to buy" | each rule has a plain-English template per field (`ind.rsi(14) < 30` → "RSI(14) is oversold, under 30") |
| `entry.size` | "How much" | mode template |
| `take_profit.ladder` | "Nibble on the way up" | rung list, sum banked, runner share |
| `take_profit.runner` | "The runner" | TTP numbers |
| `reentry` | "Buy the dip back" + "Cash it out" | retrace, formula in words, cash-out % |
| `stop` | "The fail-safe" | pct, anchor behaviour |
| all of the above | one-trade chart | synthetic price path that exercises every card, drawn from the same numbers |
| always | honesty note, robot, watermark, © strip | fixed by the house style |

Field templates live in one table (`resources/lang/en/strategy_explain.php`), so adding an
indicator means adding one row there too. A strategy the renderer cannot fully explain (unknown
field, formula it cannot verbalise) still gets a poster, with that card showing the raw rule.

## Build order

Each phase is shippable on its own and lands behind `schema_version` dispatch, so v1 users never
see a change.

| Phase | Scope | Proof |
|---|---|---|
| A. Indicators | `ind.*` parametric fields on any stored timeframe, `crosses_*`, field-to-field values, per-rule `tf`, v1 aliases | unit tests per indicator against known series; a rule test for each op |
| B. Schema | validator v2, formula parser, `params` substitution, `SchemaMigrator::v1ToV2`, docs | every v1 example round-trips to identical backtest fills; fuzz the formula parser |
| C. Engine | `take_profit` ladder (percent-of-original, reset on add), `stop` anchor, `reentry` + `cash_out`, runner TTP, lot tracking in `Desk` and `Backtester` | the π strategy backtests on a synthetic tape that exercises every rung, re-buy, cash-out, and the stop; closed-form ending equity asserted |
| D. Agent + templates | `strategy_json` accepts v2, `smx-pi-take-profit.json` v2 ships in `shoemoneyx-strategies`, poster JSON block switches to v2 | agent round-trip test: paste → validate → backtest |
| E. Card builder | Vue editor over v2: section board, typed cards, signal references, formula field with variable chips, live validation, JSON view | e2e: build the π strategy by drag and drop, export, diff against the shipped file |
| F. Hub poster | server-side renderer, explain templates, share image | golden-image test on the π strategy |

A before B before C. D can start with B. E and F start after C.
