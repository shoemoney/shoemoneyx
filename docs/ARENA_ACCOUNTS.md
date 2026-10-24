# Main desk and arena account history

The main desk uses rows whose `arena_seat_id` is null. Each arena seat owns its paper positions,
fills, cash, equity snapshots, and desk runs; candidates inherit account ownership through their
`desk_run_id`. Retiring a seat keeps its history. Deleting a seat removes its attributed runs and
their candidate history instead of moving them into the main desk.

The dashboard, landing snapshot, end-of-day report, and default positions, fills, runs, latest-run,
and candidates lists show the main desk only. To browse a seat's history, supply
`arena_seat_id=<seat id>` on `GET /api/positions`, `/api/fills`, `/api/runs`, `/api/runs/latest`, or
`/api/candidates`. The selector must identify an existing seat. List endpoints retain their existing
mode filtering; candidates now follow their run's mode as well as its account. Direct position and
run detail URLs continue to work for their specific records.

Before the run-ownership migration, desk runs did not identify their account. The migration can
recover a seat only when all linked fills belong to that one seat. Runs without fills, or runs
whose fills identify multiple accounts, retain their legacy null attribution because there is no
reliable evidence to reconstruct their owner. Historical report counts may therefore still include
these unattributed old runs. New cycles always record ownership, including cycles that never fill.
