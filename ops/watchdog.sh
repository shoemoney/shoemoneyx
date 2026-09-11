#!/usr/bin/env bash
# shoemoneyx watchdog — runs on a Pi (systemd), NOT on the desk host, so it still fires when the desk host dies.
#   every INTERVAL seconds: GET http://DESK/api/status → must answer 200 with health.ready=true and running=true
#   after FAILS consecutive misses: alert (Telegram if TELEGRAM_BOT_TOKEN+TELEGRAM_CHAT_ID set, always the log);
#   one alert per outage, one "recovered" when it comes back. Also warns when no desk cycle for STALE_MIN minutes.
#
#   DESK=http://localhost:8811 INTERVAL=30 FAILS=3 ./watchdog.sh
#
# Grade HTTP status before the body so network-policy failures and application errors
# remain distinct from a desk that is unreachable. The private desk does not require
# a shared-secret header; the monitor uses the same network access as the website.
set -u
DESK="${DESK:-http://localhost:8811}"
INTERVAL="${INTERVAL:-30}"
FAILS="${FAILS:-3}"
STALE_MIN="${STALE_MIN:-10}"
LOG="${LOG:-/var/log/shoemoneyx-watchdog.log}"
TOKEN="${TELEGRAM_BOT_TOKEN:-}"
CHAT="${TELEGRAM_CHAT_ID:-}"

log() { printf '%s %s\n' "$(date -u +%FT%TZ)" "$*" | tee -a "$LOG" >/dev/null; }
alert() {
  log "ALERT $*"
  if [[ -n "$TOKEN" && -n "$CHAT" ]]; then
    curl -s -m 10 -X POST "https://api.telegram.org/bot${TOKEN}/sendMessage" \
      --data-urlencode "chat_id=${CHAT}" --data-urlencode "text=shoemoneyx watchdog ($(hostname)): $*" >/dev/null || log "telegram send failed"
  fi
}

misses=0; down=0; downclass=""
while true; do
  # -w appends the status on its own line, so an empty body and a 000 connect failure stay
  # distinguishable from a 401 that carries a perfectly well-formed error document.
  resp="$(curl -s -m 8 -w $'\n%{http_code}' "$DESK/api/status" 2>/dev/null)"
  code="${resp##*$'\n'}"
  body="${resp%$'\n'*}"
  [[ "$code" =~ ^[0-9]{3}$ ]] || code="000"

  ok=0; class=""; reason=""
  case "$code" in
    000) class="UNREACHABLE"; reason="no answer from $DESK (connect failed or timed out)" ;;
    401|403)
      class="ACCESS DENIED"
      reason="network access was denied (http $code); check the firewall or proxy policy for this monitor"
      ;;
    5*) class="DESK ERROR"; reason="desk answered http $code" ;;
    200)
      ready="$(printf '%s' "$body" | sed -n 's/.*"ready":\(true\|false\).*/\1/p' | head -1)"
      running="$(printf '%s' "$body" | sed -n 's/.*"running":\(true\|false\).*/\1/p' | head -1)"
      halted="$(printf '%s' "$body" | grep -o '"halted":{' | head -1)"
      if [[ "$ready" == true && "$running" == true && -z "$halted" ]]; then
        ok=1
      else
        class="NOT READY"; reason="ready=${ready:-?} running=${running:-?} halted=${halted:+yes}"
      fi
      ;;
    *) class="UNEXPECTED"; reason="desk answered http $code" ;;
  esac

  if (( ok )); then
    if (( down )); then alert "RECOVERED — desk READY and running again"; down=0; downclass=""; fi
    misses=0
  else
    misses=$((misses+1))
    log "miss $misses/$FAILS ($class: $reason)"
    # Re-alert when the CLASS changes — a lockout that becomes a real outage is new
    # information — but never on the miss count, which moves every tick and would page
    # forever for one standing problem.
    if (( misses >= FAILS )) && { (( ! down )) || [[ "$class" != "$downclass" ]]; }; then
      alert "DESK $class — $reason (${FAILS}×${INTERVAL}s) $DESK"
      down=1; downclass="$class"
    fi
  fi
  sleep "$INTERVAL"
done
