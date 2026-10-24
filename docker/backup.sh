#!/bin/bash
# Nightly MariaDB dump for the compose `backup` service (mariadb:11 image, which ships mariadb-dump).
# Writes /backups/shoemoneyx-<UTC timestamp>.sql.gz atomically (tmp file, gzip -t, rename), keeps the
# newest BACKUP_KEEP (default 7), runs once at start if the newest dump is older than a day, then at
# BACKUP_AT (UTC, default 03:15) every day. A failed dump is retried hourly and never deletes old ones.
set -uo pipefail
umask 077

DEST=/backups
KEEP="${BACKUP_KEEP:-7}"
[[ "$KEEP" =~ ^[1-9][0-9]*$ ]] || KEEP=7
AT="${BACKUP_AT:-03:15}"

# Password goes in a 0600 option file, not the environment, so no child process inherits it.
CNF="$(mktemp)"
trap 'rm -f "$CNF"' EXIT
pw="${DB_PASSWORD:?DB_PASSWORD required}"
pw="${pw//\\/\\\\}"
pw="${pw//\"/\\\"}"
printf '[client]\npassword="%s"\n' "$pw" > "$CNF"
unset pw DB_PASSWORD

dump() {
  local out tmp
  out="$DEST/shoemoneyx-$(date -u +%Y%m%d-%H%M%S).sql.gz"
  tmp="$out.tmp"
  # pipefail: a failed mariadb-dump fails the whole pipeline even though gzip succeeds
  if mariadb-dump --defaults-extra-file="$CNF" -h "${DB_HOST:-mariadb}" -u "${DB_USERNAME:-shoemoneyx}" --single-transaction --quick --routines --events shoemoneyx | gzip -c > "$tmp" \
    && gzip -t "$tmp" \
    && mv "$tmp" "$out"; then
    echo "[backup] wrote $out ($(du -h "$out" | cut -f1))"
    # shellcheck disable=SC2012 # filenames are our own timestamps
    ls -1t "$DEST"/shoemoneyx-*.sql.gz | tail -n +"$((KEEP + 1))" | xargs -r rm -f --
    return 0
  fi
  rm -f "$tmp"
  echo "[backup] dump FAILED" >&2
  return 1
}

newest_age() {
  local f
  # shellcheck disable=SC2012 # filenames are our own timestamps
  f="$(ls -1t "$DEST"/shoemoneyx-*.sql.gz 2>/dev/null | head -n1)"
  [[ -n "$f" ]] || { echo 999999; return; }
  echo $(( $(date +%s) - $(stat -c %Y "$f") ))
}

if [[ "$(newest_age)" -gt 86400 ]]; then
  until dump; do sleep 3600; done
fi

while true; do
  now="$(date -u +%s)"
  next="$(date -u -d "$(date -u +%F) $AT" +%s)"
  [[ "$next" -gt "$now" ]] || next=$((next + 86400))
  sleep $((next - now))
  until dump; do sleep 3600; done
done
