#!/bin/sh
# heartbeat-check.sh <name> <max-age-seconds>
# Exit 0 when storage/framework/heartbeat-<name> (written by App\Desk\Heartbeat) is fresher than
# max-age. Used by the compose healthchecks and by the entrypoint watchdog; plain sh, no artisan boot.
f="${HEARTBEAT_DIR:-/app/storage/framework}/heartbeat-${1:?name}"
max="${2:?max age}"
[ -f "$f" ] || exit 1
[ $(( $(date +%s) - $(stat -c %Y "$f" 2>/dev/null || stat -f %m "$f") )) -lt "$max" ]
