#!/usr/bin/env bash
# ROLE=worker   → N queue workers on QUEUES (default "backtests"; Pis: QUEUES=backtests-light) (N = WORKERS, default = cores)
# ROLE=feeder   → Coinbase websocket feeder
# ROLE=desk     → trading loop (desk:run)   [needs the same .env as the desk host]
# ROLE=artisan  → run any artisan command: docker run … -e ROLE=artisan shoemoneyx desk:backtest …
# ROLE=web      → migrate, then php-fpm in the foreground (fronted by the nginx image over 443)
# ROLE=queue    → the desk's own default queue (distinct from the worker role's backtests queue)
# ROLE=schedule → schedule:work (daily report, backtest loop, strategy sync, contest reports)
# ROLE=reverb   → websocket firehose for the dashboard
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
  feeder)   exec node feeder/feed.mjs ;;
  desk)     exec php artisan desk:run ;;
  artisan)  shift 0; exec php artisan "$@" ;;
  web)      php artisan migrate --force; exec php-fpm -F ;;
  queue)    exec php artisan queue:work redis --tries=1 --timeout=3600 --sleep=2 ;;
  schedule) exec php artisan schedule:work ;;
  reverb)   exec php artisan reverb:start --host=0.0.0.0 --port=8812 ;;
  *)        exec "$@" ;;
esac
