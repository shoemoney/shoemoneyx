#!/usr/bin/env bash
# docker pull and go: generate .env on first run (never touch it again), then `docker compose up -d`.
# Idempotent — safe to run every time you want the desk (re)started.
set -euo pipefail
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

ENV_FILE=.env

rand() { openssl rand -base64 "$1" | tr -dc 'A-Za-z0-9' | head -c "$2"; }

set_env() {
  local key="$1" value="$2" escaped
  escaped="$(printf '%s' "$value" | sed -e 's/[\/&]/\\&/g')"
  if grep -q "^${key}=" "$ENV_FILE"; then
    sed -i.bak "s/^${key}=.*/${key}=${escaped}/" "$ENV_FILE" && rm -f "${ENV_FILE}.bak"
  else
    printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
  fi
}

if [[ ! -f "$ENV_FILE" ]]; then
  echo "==> no .env found — generating one (like the AMI's first-boot.sh, but for compose)"
  cp .env.example "$ENV_FILE"

  # REVERB_APP_KEY is the pusher-protocol PUBLIC key: it's baked into the frontend bundle at image
  # build time (Dockerfile's frontend-build stage), so it has to be this exact fixed value or the
  # browser and the reverb server disagree about which "app" they're talking about. Everything else
  # here is per-install and safe to randomize.
  declare -A OVERRIDES=(
    [APP_KEY]="base64:$(openssl rand -base64 32)"
    [APP_ENV]="production"
    [APP_DEBUG]="false"
    [APP_URL]="https://localhost"
    [DB_CONNECTION]="mysql"
    [DB_HOST]="mariadb"
    [DB_PORT]="3306"
    [DB_DATABASE]="shoemoneyx"
    [DB_USERNAME]="shoemoneyx"
    [DB_PASSWORD]="$(rand 32 32)"
    [REDIS_CLIENT]="phpredis"
    [REDIS_HOST]="redis"
    [REDIS_PORT]="6379"
    [QUEUE_CONNECTION]="redis"
    [CACHE_STORE]="redis"
    [DESK_MODE]="paper"
    [EXCHANGE]="coinbase"
    [MASTER_PASSWORD]="${MASTER_PASSWORD:-$(rand 32 24)}"
    [HUB_URL]="https://hub.shoemoneyx.com"
    [BROADCAST_CONNECTION]="reverb"
    [REVERB_APP_ID]="shoemoneyx"
    [REVERB_APP_KEY]="shoemoneyx-desk"
    [REVERB_APP_SECRET]="$(rand 32 32)"
    [REVERB_HOST]="reverb"
    [REVERB_PORT]="8812"
    [REVERB_SCHEME]="http"
    [REVERB_SERVER_HOST]="0.0.0.0"
    [REVERB_SERVER_PORT]="8812"
  )
  # An image's first boot can hand in a known bootstrap password (the EC2 instance ID) and the
  # login-page hint / exposed-desk flag the app reads; a plain self-hoster leaves these unset.
  [[ -n "${MASTER_PASSWORD_HINT:-}" ]] && OVERRIDES[MASTER_PASSWORD_HINT]="$MASTER_PASSWORD_HINT"
  [[ -n "${DESK_REQUIRE_MASTER_PASSWORD:-}" ]] && OVERRIDES[DESK_REQUIRE_MASTER_PASSWORD]="$DESK_REQUIRE_MASTER_PASSWORD"
  for key in "${!OVERRIDES[@]}"; do
    set_env "$key" "${OVERRIDES[$key]}"
  done
  chmod 600 "$ENV_FILE"
else
  echo "==> .env exists, leaving it alone"
fi

echo "==> docker compose up -d"
docker compose up -d

echo -n "==> waiting for https://localhost/api/status"
ready=0
for _ in $(seq 1 90); do
  code="$(curl -sk -o /dev/null -w '%{http_code}' https://localhost/api/status || true)"
  if [[ "$code" == "200" || "$code" == "401" ]]; then
    ready=1
    break
  fi
  echo -n "."
  sleep 2
done
echo
[[ "$ready" == "1" ]] || { echo "!! /api/status never answered — check: docker compose logs web nginx migrate" >&2; exit 1; }

MASTER_PASSWORD="$(grep '^MASTER_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)"
echo
echo "shoemoneyx desk is up: https://localhost"
echo "MASTER_PASSWORD=$MASTER_PASSWORD"
echo "(X-Desk-Token header, or ?token= query param, on every API call)"
