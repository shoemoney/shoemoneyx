#!/usr/bin/env bash
# /opt/shoemoneyx-acme.sh — per-instance, browser-trusted TLS for the desk.
#
#   acme.sh issue        always request a certificate (first boot)
#   acme.sh renew        request one only if needed: nothing installed yet, the target (public IP or
#                        DOMAIN tag) changed, the installed cert doesn't cover it, or it expires soon
#   acme.sh close-port   close inbound tcp/80 if a previous run left it open (systemd ExecStopPost)
#
# The target is the instance's DOMAIN tag if present, else its public IPv4 from IMDS. An IP gets a
# Let's Encrypt `shortlived` (~6 day) certificate over HTTP-01 (certbot >= 5.4, --ip-address);
# DNS-01 cannot validate IPs and certbot has no TLS-ALPN-01. Port 80 is open only while certbot runs
# and is closed by an EXIT trap, plus ExecStopPost for the SIGKILL/timeout case, plus the firewall
# service's full ufw reset on every boot. The cert lands in the compose `tls-data` volume, replacing
# the per-install self-signed pair that nginx already reads (docker/nginx-tls-init.sh leaves an
# existing pair alone) as one atomic symlink swap of a generation directory holding a verified
# cert+key pair; the original self-signed pair is kept as the fallback. No cert, account key
# or email exists in the AMI: certbot registers an anonymous account here, on the instance.
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/shoemoneyx}"
PROJECT="${COMPOSE_PROJECT:-shoemoneyx}"
STATE_DIR="${STATE_DIR:-/var/lib/shoemoneyx/acme}"
LIVE_ROOT="${LE_LIVE_ROOT:-/etc/letsencrypt/live}"
CERT_NAME=shoemoneyx-desk
LOCK_FILE="${LOCK_FILE:-/run/shoemoneyx-acme.lock}"
IP_RENEW_BEFORE_SECS=$((3 * 24 * 3600))     # of ~6.7 days: leaves ~6 twice-daily retries
DOMAIN_RENEW_BEFORE_SECS=$((21 * 24 * 3600))
IMDS_BASE=http://169.254.169.254/latest

log() { echo "[acme] $*"; }

imds() {
  local token
  token="$(curl -sf -m 5 -X PUT "$IMDS_BASE/api/token" -H 'X-aws-ec2-metadata-token-ttl-seconds: 60' 2>/dev/null || true)"
  curl -sf -m 5 -H "X-aws-ec2-metadata-token: $token" "$IMDS_BASE/meta-data/$1" 2>/dev/null || true
}

port80_is_open() { ufw status 2>/dev/null | grep -qE '^80(/tcp)?[[:space:]]+ALLOW'; }

close_80() {
  local _
  for _ in 1 2 3; do
    port80_is_open || return 0
    ufw --force delete allow in 80/tcp >/dev/null 2>&1 || true
  done
  port80_is_open && log "WARNING: inbound 80 is still open; the next boot's firewall reset will close it" || true
}

# Compose volumes this script seeds before `docker compose up` ever runs: tls-data (the cert nginx
# reads) and tls-status (a one-line trusted|fallback marker the web container reads, read-only).
volume_dir() {
  local name="${1:-tls-data}"
  docker volume inspect "${PROJECT}_${name}" -f '{{.Mountpoint}}' 2>/dev/null
}

# Compose adopts a volume carrying its project/volume labels, so it can be seeded before the stack
# exists and nginx's very first start already has the cert.
ensure_volume() {
  local name="$1"
  docker volume inspect "${PROJECT}_${name}" >/dev/null 2>&1 && return 0
  docker volume create \
    --label "com.docker.compose.project=$PROJECT" \
    --label "com.docker.compose.volume=$name" \
    "${PROJECT}_${name}" >/dev/null
}

# trusted | fallback, the target and a timestamp: AWS first login refuses credentials until
# trusted activation matches the requested host over HTTPS. A missing status fails closed;
# best effort here means a status-write failure must not discard a successfully issued cert.
write_status() {
  local state="$1" vol
  ensure_volume tls-status || return 0
  vol="$(volume_dir tls-status)" && [[ -d "$vol" ]] || return 0
  printf '%s %s:%s %s\n' "$state" "${TARGET_KIND:-none}" "${TARGET_ID:-none}" "$(date -u +%FT%TZ)" > "$vol/.status.new" \
    && chmod 644 "$vol/.status.new" && mv -f "$vol/.status.new" "$vol/status" || true
}

# Returns 0 when nginx picked the new cert up (or is not running yet and will read it on start).
reload_nginx() {
  local cid
  cid="$(cd "$APP_DIR" && docker compose -p "$PROJECT" ps -q nginx 2>/dev/null || true)"
  [[ -n "$cid" ]] || { log "nginx not running yet; it reads the new cert when it starts"; return 0; }
  if docker exec "$cid" nginx -s reload >/dev/null; then
    log "nginx reloaded"
    return 0
  fi
  log "WARNING: nginx reload failed; will retry on the next run"
  return 1
}

# A published cert is not live until nginx has reloaded. The marker survives a failed reload so the
# next run retries it, and the login-page status stays "fallback" until it has actually succeeded.
activate_pending() {
  [[ -e "$STATE_DIR/pending-reload" ]] || return 0
  reload_nginx || return 1
  rm -f "$STATE_DIR/pending-reload"
}

mark_pending() {
  mkdir -p "$STATE_DIR" && : > "$STATE_DIR/pending-reload"
}

resolve_target() {
  TARGET_KIND=""
  TARGET_ID=""
  local domain ip
  domain="$(imds tags/instance/DOMAIN)"
  if [[ "$domain" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]]; then
    TARGET_KIND=dns TARGET_ID="$domain" RENEW_BEFORE="$DOMAIN_RENEW_BEFORE_SECS"
    return 0
  fi
  ip="$(imds public-ipv4)"
  if [[ "$ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
    TARGET_KIND=ip TARGET_ID="$ip" RENEW_BEFORE="$IP_RENEW_BEFORE_SECS"
    return 0
  fi
  return 1
}

# A certificate and a key belong together iff their public keys are identical. An openssl failure
# (empty output) never counts as a match.
pair_matches() {
  local cert_pub key_pub
  cert_pub="$(openssl x509 -in "$1" -noout -pubkey 2>/dev/null)" || return 1
  key_pub="$(openssl pkey -in "$2" -pubout 2>/dev/null)" || return 1
  [[ -n "$cert_pub" && "$cert_pub" == "$key_pub" ]]
}

# cert_good <cert> <key> <seconds>: the pair matches, covers the current target, and outlives <seconds>.
cert_good() {
  local check
  [[ -s "$1" && -s "$2" ]] || return 1
  pair_matches "$1" "$2" || return 1
  openssl x509 -in "$1" -noout -checkend "$3" >/dev/null 2>&1 || return 1
  if [[ "$TARGET_KIND" == ip ]]; then check=-checkip; else check=-checkhost; fi
  openssl x509 -in "$1" -noout "$check" "$TARGET_ID" 2>/dev/null | grep -q 'does match'
}

# True when the installed pair is for the current target and still good for > $1 seconds.
installed_cert_ok() {
  local vol recorded
  vol="$(volume_dir)" || return 1
  [[ -s "$STATE_DIR/target" ]] || return 1
  recorded="$(cat "$STATE_DIR/target")"
  [[ "$recorded" == "$TARGET_KIND:$TARGET_ID" ]] || return 1
  cert_good "$vol/selfsigned.crt" "$vol/selfsigned.key" "$1"
}

# Same certificate the nginx image mints for itself (docker/nginx-tls-init.sh): the fallback has
# to exist before a trusted cert replaces the pair, or there would be nothing to go back to.
make_fallback() {
  local vol="$1"
  [[ -s "$vol/fallback-selfsigned.crt" && -s "$vol/fallback-selfsigned.key" ]] && return 0
  openssl req -x509 -nodes -days 3650 -newkey rsa:2048 -keyout "$vol/fallback-selfsigned.key" \
    -out "$vol/fallback-selfsigned.crt" -subj "/CN=shoemoneyx-desk" >/dev/null 2>&1 || return 1
  chmod 600 "$vol/fallback-selfsigned.key" && chmod 644 "$vol/fallback-selfsigned.crt"
}

# Replace the symlink $2 (pointing at a directory) with the new symlink $1 in one rename(2).
swap_symlink() {
  mv -fT "$1" "$2" 2>/dev/null || mv -fh "$1" "$2"
}

# nginx reads /etc/ssl/shoemoneyx/selfsigned.{crt,key}. To change cert AND key as one atomic step,
# those two names are fixed symlinks into `current/`, itself a symlink to a generation directory
# holding a matching pair. Publishing a new pair swaps `current` only.
link_layout() {
  local vol="$1" gen=gen-init-$$ ext
  [[ -L "$vol/selfsigned.crt" && -L "$vol/selfsigned.key" && -d "$vol/current" ]] && return 0
  mkdir -p "$vol/$gen" || return 1
  if [[ -s "$vol/selfsigned.crt" && -s "$vol/selfsigned.key" ]]; then
    cp -L "$vol/selfsigned.crt" "$vol/$gen/tls.crt" && cp -L "$vol/selfsigned.key" "$vol/$gen/tls.key" || return 1
  else
    make_fallback "$vol" || return 1
    cp "$vol/fallback-selfsigned.crt" "$vol/$gen/tls.crt" && cp "$vol/fallback-selfsigned.key" "$vol/$gen/tls.key" || return 1
  fi
  chmod 644 "$vol/$gen/tls.crt" && chmod 600 "$vol/$gen/tls.key" || return 1
  ln -sfn "$gen" "$vol/current" || return 1
  # Both names resolve through `current`, whose pair is the old content, so a crash in here is harmless.
  for ext in crt key; do
    # A kill before the rename can leave this exact staging link behind. Reuse only our known
    # link; leave unrelated files or links untouched instead of overwriting them on a retry.
    if [[ -L "$vol/.link.$ext" ]]; then
      [[ "$(readlink "$vol/.link.$ext")" == "current/tls.$ext" ]] || return 1
    else
      ln -s "current/tls.$ext" "$vol/.link.$ext" || return 1
    fi
    mv -f "$vol/.link.$ext" "$vol/selfsigned.$ext" || return 1
  done
}

# publish_pair <vol> <cert> <key>: stage the pair in a fresh generation directory, verify it, and
# make it live with a single symlink swap. On any failure the active pair is untouched.
publish_pair() {
  local vol="$1" crt="$2" key="$3" gen
  pair_matches "$crt" "$key" || { log "certificate and key do not match; refusing to publish them"; return 1; }
  link_layout "$vol" || { log "could not set up the certificate layout in the tls-data volume"; return 1; }
  gen="gen-$(date +%s)-$$"
  mkdir -p "$vol/$gen" || return 1
  if ! { cp "$crt" "$vol/$gen/tls.crt" && cp "$key" "$vol/$gen/tls.key" \
      && chmod 644 "$vol/$gen/tls.crt" && chmod 600 "$vol/$gen/tls.key" \
      && ln -s "$gen" "$vol/.current.new"; }; then
    rm -rf "${vol:?}/$gen" "$vol/.current.new"
    return 1
  fi
  if ! swap_symlink "$vol/.current.new" "$vol/current"; then
    rm -rf "${vol:?}/$gen" "$vol/.current.new"
    return 1
  fi
  find "$vol" -maxdepth 1 -name 'gen-*' ! -name "$gen" -exec rm -rf {} + 2>/dev/null || true
}

install_cert() {
  local vol live="$LIVE_ROOT/$CERT_NAME"
  ensure_volume tls-data || return 1
  vol="$(volume_dir)" && [[ -d "$vol" ]] || { log "cannot resolve the tls-data volume directory"; return 1; }
  [[ -s "$live/fullchain.pem" && -s "$live/privkey.pem" ]] || { log "no certificate in $live"; return 1; }
  umask 077
  make_fallback "$vol" || { log "could not create the self-signed fallback"; return 1; }
  publish_pair "$vol" "$live/fullchain.pem" "$live/privkey.pem" || return 1
  mkdir -p "$STATE_DIR" || return 1
  echo "$TARGET_KIND:$TARGET_ID" > "$STATE_DIR/target" || return 1
  mark_pending
}

restore_fallback() {
  local vol
  vol="$(volume_dir)" && [[ -d "$vol" ]] || return 0
  make_fallback "$vol" || return 1
  publish_pair "$vol" "$vol/fallback-selfsigned.crt" "$vol/fallback-selfsigned.key" || return 1
  rm -f "$STATE_DIR/target"
  log "restored the per-install self-signed certificate (browsers will warn until issuance succeeds)"
  mark_pending && activate_pending || true
}

issue_cert() {
  local args=(certonly --standalone --non-interactive --agree-tos --register-unsafely-without-email
    --cert-name "$CERT_NAME" --force-renewal)
  if [[ "$TARGET_KIND" == ip ]]; then
    args+=(--preferred-profile shortlived --ip-address "$TARGET_ID")
  else
    args+=(-d "$TARGET_ID")
  fi
  [[ -n "${ACME_SERVER:-}" ]] && args+=(--server "$ACME_SERVER")

  # Never reuse a lineage here: one naming a previous IP must not be expanded or renewed in place.
  certbot delete --cert-name "$CERT_NAME" --non-interactive >/dev/null 2>&1 || true

  close_80
  ufw allow in 80/tcp >/dev/null || { log "could not open inbound 80"; return 1; }
  log "inbound 80 open for the HTTP-01 challenge ($TARGET_KIND $TARGET_ID)"
  local rc=0
  timeout 180 certbot "${args[@]}" || rc=$?
  close_80
  log "inbound 80 closed"
  return "$rc"
}

main() {
  local mode="${1:-}" live="$LIVE_ROOT/$CERT_NAME" got=0
  case "$mode" in
    close-port) close_80; return 0 ;;
    issue | renew) ;;
    *) echo "usage: $0 issue|renew|close-port" >&2; return 2 ;;
  esac

  exec 9>"$LOCK_FILE"
  flock -n 9 || { log "another run is in progress, skipping"; return 0; }
  trap 'exit 143' TERM INT HUP
  trap close_80 EXIT

  if ! resolve_target; then
    log "no DOMAIN tag and no public IPv4 from IMDS: staying on the per-install self-signed certificate"
    write_status fallback
    return 0
  fi

  if [[ "$mode" == renew ]] && installed_cert_ok "$RENEW_BEFORE"; then
    if activate_pending; then
      log "installed certificate for $TARGET_KIND $TARGET_ID is current, nothing to do"
      write_status trusted
      return 0
    fi
    write_status fallback
    return 1
  fi

  if [[ "$mode" == renew ]] && cert_good "$live/fullchain.pem" "$live/privkey.pem" "$RENEW_BEFORE"; then
    # An earlier run issued this but failed to install it: use it, don't spend another issuance.
    log "reusing the certificate certbot already issued for $TARGET_KIND $TARGET_ID"
    got=1
  elif issue_cert; then
    got=1
  fi

  if [[ "$got" -eq 1 ]] && install_cert; then
    if activate_pending; then
      log "certificate for $TARGET_KIND $TARGET_ID installed and active"
      write_status trusted
      return 0
    fi
    log "certificate installed but nginx has not reloaded yet"
    write_status fallback
    return 1
  fi

  log "issuance or install failed (rate limit, network, or port 80 not reachable from the internet: check the security group)"
  if installed_cert_ok 0; then
    if [[ -e "$STATE_DIR/pending-reload" ]]; then write_status fallback; else write_status trusted; fi
  else
    restore_fallback || log "WARNING: could not restore the self-signed fallback"
    write_status fallback
  fi
  return 1
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  main "$@"
fi
