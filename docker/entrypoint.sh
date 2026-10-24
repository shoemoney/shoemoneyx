#!/usr/bin/env bash
# ROLE=worker   → N queue workers on QUEUES (default "backtests"; Pis: QUEUES=backtests-light) (N = WORKERS, default = cores)
# ROLE=feeder   → Coinbase websocket feeder
# ROLE=desk     → trading loop (desk:run)   [needs the same .env as the desk host]
# ROLE=artisan  → run any artisan command: docker run … -e ROLE=artisan shoemoneyx desk:backtest …
# ROLE=web      → php-fpm in the foreground (fronted by the nginx image over 443); schema is the compose `migrate` one-shot's job
# ROLE=queue    → the desk's own default queue (distinct from the worker role's backtests queue)
# ROLE=schedule → schedule:work (daily report, backtest loop, strategy sync, contest reports)
# ROLE=reverb   → websocket firehose for the dashboard
set -euo pipefail
cd /app

# supervise <heartbeat-name> <max-age-s> <cmd...>: run cmd, and if its heartbeat file goes stale kill it
# so the container exits and compose's restart policy brings it back (plain compose never restarts a
# merely "unhealthy" container). SIGTERM/INT are forwarded so `docker stop` still shuts the command down.
supervise() {
  local name="$1" max="$2" pid dog rc=0
  shift 2
  touch "/app/storage/framework/heartbeat-$name"
  "$@" &
  pid=$!
  trap 'kill -TERM "$pid" 2>/dev/null' TERM INT
  (
    while sleep 30; do
      sh /app/docker/heartbeat-check.sh "$name" "$max" || {
        echo "[watchdog] $name heartbeat stale (>${max}s), killing pid $pid" >&2
        kill -9 "$pid"
        break
      }
    done
  ) &
  dog=$!
  wait "$pid" || rc=$?
  while kill -0 "$pid" 2>/dev/null; do wait "$pid" || rc=$?; done
  kill "$dog" 2>/dev/null || true
  exit "$rc"
}
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
  # desk: one iteration may run a whole cycle (desk.cycle_budget_seconds, default 1200) plus in-flight orders; killing
  # mid-order is worse than waiting, so the limit stays above 2x the budget. Raise DESK_HEARTBEAT_MAX with the budget.
  desk)     supervise desk "${DESK_HEARTBEAT_MAX:-2400}" php -d memory_limit="${PHP_MEMORY_LIMIT:-1G}" artisan desk:run ;;
  artisan)  shift 0; exec php artisan "$@" ;;
  web)      exec php-fpm -F ;;
  # queue: a backtest job may legitimately hold the worker for its 3600s timeout, so the heartbeat job can wait that long.
  queue)    supervise queue 4200 php -d memory_limit="${PHP_MEMORY_LIMIT:-1G}" artisan queue:work redis --tries=1 --timeout=3600 --sleep=2 --memory="${QUEUE_MEMORY_MB:-1024}" ;;
  # Single schedule container: a predecessor killed mid-command leaves withoutOverlapping mutexes behind.
  schedule)
    # Do not advertise a healthy scheduler while predecessor mutexes still block its jobs.
    # Retry brief cache outages, then let the container restart policy retry startup.
    for attempt in 1 2 3; do
      if php artisan schedule:clear-cache; then
        supervise schedule 300 php artisan schedule:work
      fi
      echo "[schedule] mutex cleanup failed (attempt $attempt/3)" >&2
      if [[ "$attempt" -lt 3 ]]; then sleep 2; fi
    done
    echo "[schedule] refusing startup: mutex cleanup did not succeed" >&2
    exit 1 ;;
  reverb)   exec php artisan reverb:start --host=0.0.0.0 --port=8812 ;;
  *)        exec "$@" ;;
esac
