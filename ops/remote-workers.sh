#!/usr/bin/env bash
# Run N backtest workers on a remote Mac INSIDE an ssh session (each respawns after --max-jobs; without the loop the pool decayed to 0 and systemd restarted it all at once) (children of sshd), instead of under
# that Mac's own pm2 daemon. macOS's Local Network privacy check blocks launchd-parented daemons from
# reaching LAN hosts ("No route to host" / EHOSTUNREACH) but leaves ssh sessions alone.
#   usage: [JUMP=<tailscale ip of this box>] remote-workers.sh <host-or-ip> <N>
# From a tmux/pm2-held loop the LAN connect itself is blocked, and without -4 ssh silently fell through to the
# host's "::" entry (= localhost) and started the pool HERE. Fix: -4 (fail loudly) + JUMP through this box's own
# Tailscale address — the tunnel is not gated, and the second hop runs from an sshd-parented process, which is.
set -u
host="${1:?host}"; n="${2:-24}"; me="$(hostname -s)"
exec ssh -4 ${JUMP:+-J "$JUMP"} -o BatchMode=yes -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -o ExitOnForwardFailure=yes "$host" \
  "[ \"\$(hostname -s)\" != \"$me\" ] || { echo \"refusing: landed on \$(hostname -s) — that is this box (Local Network block?)\"; exit 97; }; \
   cd ~/shoemoneyx && git pull -q 2>/dev/null; php artisan config:clear >/dev/null 2>&1; trap 'kill 0' EXIT INT TERM HUP; \
   for i in \$(seq 1 $n); do ( while true; do php artisan queue:work redis --queue=backtests,backtests-light --tries=1 --timeout=3600 --memory=1024 --sleep=2 --max-jobs=200 --name=worker-\$(hostname -s)-\$i >> storage/logs/worker-\$i.log 2>&1 || true; sleep 1; done ) & done; \
   echo \"$n workers up on \$(hostname -s)\"; wait"
