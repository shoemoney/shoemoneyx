# RISK — force-close escalation

RISK (`Desk::runRiskSweep()`, one sweep per open position, on its own timer — see the README's
`RISK` box) is final authority: it decides close/trim/add independent of SCAN→VET→SIZE→FILLS.
Most sweeps price the position off `ProductStats` and hand the row to the strategy's `risk()`.
This doc covers the other path: what happens when a position **cannot be priced at all**.

## The ladder

A position hits this ladder from either of two routes, which share one escalation state:

- **`stats === null`** — `statsWithRetries()` exhausted `risk.stale_data_retries` retries and the
  feed never answered.
- **`stats->price <= 0`** — a real (non-null) stats row, but the product has no closed 1H bars yet,
  or a halted/delisted product, or a stalled candle feeder.

```
price-0 / null stats
        │
        ▼
 zero_price_sweeps < risk.max_zero_price_sweeps ?
        │ yes                              │ no
        ▼                                  ▼
   skip this sweep               ESCALATE — shared force-close ledger:
   (action "stale"),             one force_close_sweeps/attempts counter
   zero_price_sweeps++                     │
                                            ▼
                       due this sweep? (force_close_sweeps - 1) % force_close_backoff_sweeps == 0
                            │ no                              │ yes
                            ▼                                 ▼
                     back off (action "stale")        attempt close('unmeasurable')
                                                                │
                                          ┌─────────────────────┴─────────────────────┐
                                          │ close() ok                                 │ close() not ok
                                          ▼                                             ▼
                                 position closed,                          force_close_attempts++
                                 ledger no longer consulted                          │
                                                                                      ▼
                                                            force_close_attempts >= force_close_max_attempts ?
                                                                    │ no                    │ yes
                                                                    ▼                        ▼
                                                            keep escalating         TERMINAL — reports once,
                                                            (back to the top)       then every
                                                                                    force_close_rereport_sweeps'th
                                                                                    terminal sweep after that;
                                                                                    a RiskCheck row still
                                                                                    writes every terminal sweep
```

Key points that are easy to get wrong reading the code cold:

- **A `LockTimeoutException` on the close never counts as an attempt.** The contract everywhere
  else in RISK is "another chance next sweep" for a stuck mutate lock (a concurrent API close, a
  slow exchange call) — `force_close_attempts` only increments once `close()` has actually run and
  come back **not ok**. Attempts are recorded by `Desk::recordForceCloseAttemptOutcome()`, not by
  the ladder decision itself.
- **The backoff cadence is 0-based.** `force_close_backoff_sweeps = 1` means "attempt every single
  sweep, no pause" — not "never attempt" (a 1-based cadence bug here used to mean exactly that:
  the position sat unmanaged forever).
- **`zero_price_sweeps` keeps counting past the threshold**, so the escalated close's reason string
  ("price stuck at 0 for N consecutive sweeps") always reports the true stall length, not the
  threshold it crossed to get here.
- **Terminal re-reports on a slow cadence**, not never again — useful the first time, and still a
  reminder weeks later that a position is sitting there needing a human. It also keeps writing a
  `RiskCheck` row every terminal sweep, so the dashboard doesn't make a correctly-parked position
  look unmanaged.
- **The "no usable price anywhere" report** (stats, `last_price`, and `entry_price` all `<= 0` — a
  position with nothing to price a close at) rides the same cadence: it only fires on a genuine
  attempt sweep, never on a terminal one, so it can't double up with the ledger's own message.

## Config / env

| Key | Env | Default | What |
|---|---|---|---|
| `risk.stale_data_retries` | — | `2` | retries before a `null` stats row counts as "no answer" |
| `risk.max_zero_price_sweeps` | — | `5` | consecutive price-`0` sweeps before escalating to force-close |
| `risk.force_close_backoff_sweeps` | `DESK_RISK_FORCE_CLOSE_BACKOFF_SWEEPS` | `5` | only actually retry the close every Nth sweep since escalation |
| `risk.force_close_max_attempts` | `DESK_RISK_FORCE_CLOSE_MAX_ATTEMPTS` | `10` | give up (go terminal) after this many failed attempts |
| `risk.force_close_rereport_sweeps` | `DESK_RISK_FORCE_CLOSE_REREPORT_SWEEPS` | `60` | once terminal, re-report the give-up every Nth terminal sweep |

## Manual recovery

A terminal position (`action: force_close_terminal` in a RISK sweep's output, or `position.meta.
force_close_terminal_reported === true`) needs a human: the exchange has rejected the close
`force_close_max_attempts` times in a row and RISK has stopped hammering it. Once the underlying
problem is fixed (liquidity came back, the product un-halted, the feed recovered), clear the
escalation state in `position.meta` so the position starts a fresh ladder instead of going straight
back to terminal on the next sweep:

```php
$position = \App\Models\Position::where('product_id', 'BTC-USD')->where('status', 'open')->firstOrFail();
$position->meta = array_diff_key($position->meta ?? [], array_flip([
    'zero_price_sweeps',
    'force_close_sweeps',
    'force_close_attempts',
    'force_close_terminal_reported',
    'force_close_terminal_sweeps',
]));
$position->save();
```

(`php artisan tinker`, or a one-off `desk:ctl` command if you script this often.) The next RISK
sweep re-evaluates the position from a clean slate — if it's still unpriceable, it re-escalates
through the same ladder rather than silently sitting closed-off forever.
