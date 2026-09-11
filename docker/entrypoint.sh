#!/usr/bin/env bash
# ROLE=worker  → N queue workers on QUEUES (default "backtests"; Pis: QUEUES=backtests-light) (N = WORKERS, default = cores)
# ROLE=feeder  → Coinbase websocket feeder
# ROLE=desk    → trading loop (desk:run)   [needs the same .env as the desk host]
# ROLE=artisan → run any artisan command: docker run … -e ROLE=artisan shoemoneyx desk:backtest …
set -euo pipefail
cd /app
: "${ROLE:=worker}"
case "$ROLE" in
  worker)
    n="${WORKERS:-0}"; [[ "$n" -gt 0 ]] || n="$(nproc)"
    echo "shoemoneyx worker × $n on queue=backtests → redis ${REDIS_HOST:-127.0.0.1} db ${DB_HOST:-127.0.0.1}"
    pids=()
    # Each worker exits after --max-jobs and is respawned here; without the loop a container ran at 1/4 capacity
    # once three of four workers had done their 200 jobs, until the last one exited and docker restarted it.
    # set +e inside the subshell: the script's errexit is inherited and a worker's non-zero exit killed the loop.
    for i in $(seq 1 "$n"); do
      ( set +e; while true; do php artisan queue:work redis --queue="${QUEUES:-backtests}" --tries=1 --timeout=3600 --memory=1024 --sleep=2 --max-jobs=200 --name="worker-$(hostname)-$i" || true; sleep 1; done ) &
      pids+=($!)
    done
    trap 'kill "${pids[@]}" 2>/dev/null; pkill -TERM -f "artisan queue:work" 2>/dev/null; wait' TERM INT
    wait ;;
  feeder)  exec node feeder/feed.mjs ;;
  desk)    exec php artisan desk:run ;;
  artisan) shift 0; exec php artisan "$@" ;;
  *)       exec "$@" ;;
esac
