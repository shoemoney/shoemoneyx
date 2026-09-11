#!/usr/bin/env bash
# /opt/shoemoneyx-first-boot.sh — runs once via shoemoneyx-first-boot.service.
# No secrets are baked into the image: APP_KEY, MASTER_PASSWORD, and the DB
# password are all generated here, on this box, the first time it boots.
set -euo pipefail

APP_DIR=/opt/shoemoneyx
APP_USER=shoemoneyx
MARKER="$APP_DIR/.first-boot-done"
CREDS_FILE=/root/shoemoneyx-credentials.txt

log() { echo "[first-boot] $*"; }

if [[ -f "$MARKER" ]]; then
  log "already ran ($MARKER exists), skipping"
  exit 0
fi

TOKEN_MD="http://169.254.169.254/latest/api/token"
META="http://169.254.169.254/latest/meta-data"
IMDS_TOKEN="$(curl -s -X PUT "$TOKEN_MD" -H 'X-aws-ec2-metadata-token-ttl-seconds: 60' || true)"
imds() { curl -s -H "X-aws-ec2-metadata-token: $IMDS_TOKEN" "$META/$1" 2>/dev/null || true; }

PUBLIC_IP="$(imds public-ipv4)"
[[ -z "$PUBLIC_IP" ]] && PUBLIC_IP="$(imds local-ipv4)"

# DOMAIN comes from the instance's own tags via IMDS (no IAM role, no AWS CLI, no
# outbound EC2 API call needed — keeps this working under the outbound-443-only
# firewall). Requires the instance be launched with
# --metadata-options InstanceMetadataTags=enabled; if it wasn't, this is empty
# and we fall back to the self-signed cert, which is the safe default anyway.
DOMAIN="$(imds tags/instance/DOMAIN)"
[[ "$DOMAIN" == *"Not Found"* || "$DOMAIN" == *"404"* ]] && DOMAIN=""

APP_HOST="${DOMAIN:-$PUBLIC_IP}"
log "app host: $APP_HOST (domain tag: ${DOMAIN:-none})"

rand() { openssl rand -base64 "$1" | tr -dc 'A-Za-z0-9' | head -c "$2"; }

APP_KEY="base64:$(openssl rand -base64 32)"
MASTER_PASSWORD="$(rand 32 24)"
DB_PASSWORD="$(rand 32 32)"

log "creating MariaDB database + user"
mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS shoemoneyx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'shoemoneyx'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER 'shoemoneyx'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON shoemoneyx.* TO 'shoemoneyx'@'localhost';
FLUSH PRIVILEGES;
SQL

log "writing .env"
cp "$APP_DIR/.env.example" "$APP_DIR/.env"
declare -A OVERRIDES=(
  [APP_KEY]="$APP_KEY"
  [APP_ENV]="production"
  [APP_DEBUG]="false"
  [APP_URL]="https://$APP_HOST"
  [DB_CONNECTION]="mysql"
  [DB_HOST]="127.0.0.1"
  [DB_PORT]="3306"
  [DB_DATABASE]="shoemoneyx"
  [DB_USERNAME]="shoemoneyx"
  [DB_PASSWORD]="$DB_PASSWORD"
  [REDIS_CLIENT]="phpredis"
  [QUEUE_CONNECTION]="redis"
  [CACHE_STORE]="redis"
  [DESK_MODE]="paper"
  [EXCHANGE]="coinbase"
  [DESK_STRATEGY]="mr"
  [MASTER_PASSWORD]="$MASTER_PASSWORD"
  [HUB_URL]="https://hub.shoemoneyx.com"
  [BROADCAST_CONNECTION]="reverb"
  [REVERB_APP_ID]="shoemoneyx"
  [REVERB_APP_KEY]="$(rand 24 20)"
  [REVERB_APP_SECRET]="$(rand 24 32)"
  [REVERB_HOST]="$APP_HOST"
  [REVERB_PORT]="443"
  [REVERB_SCHEME]="https"
  [REVERB_SERVER_HOST]="0.0.0.0"
  [REVERB_SERVER_PORT]="8812"
)
for key in "${!OVERRIDES[@]}"; do
  value="${OVERRIDES[$key]}"
  escaped="$(printf '%s' "$value" | sed -e 's/[\/&]/\\&/g')"
  if grep -q "^${key}=" "$APP_DIR/.env"; then
    sed -i "s/^${key}=.*/${key}=${escaped}/" "$APP_DIR/.env"
  else
    echo "${key}=${value}" >> "$APP_DIR/.env"
  fi
done
chown "$APP_USER":"$APP_USER" "$APP_DIR/.env"
chmod 600 "$APP_DIR/.env"

log "migrate + product sync"
cd "$APP_DIR"
sudo -u "$APP_USER" php artisan migrate --force
sudo -u "$APP_USER" php artisan market:sync-products || log "product sync failed, non-fatal (exchange may be unreachable); desk:run will retry hourly"

if [[ -n "$DOMAIN" ]]; then
  log "DOMAIN tag present ($DOMAIN): requesting a real cert via certbot"
  if certbot --nginx --non-interactive --agree-tos -m "admin@$DOMAIN" -d "$DOMAIN" --redirect; then
    log "certbot succeeded for $DOMAIN"
  else
    log "certbot failed for $DOMAIN, staying on the self-signed cert (port 80 must be reachable for HTTP-01; open it temporarily if this box needs a real cert)"
  fi
else
  log "no DOMAIN tag, staying on the build-time self-signed cert"
fi

log "writing credentials to $CREDS_FILE"
cat > "$CREDS_FILE" <<EOF
shoemoneyx desk — first-boot credentials
generated: $(date -u +%FT%TZ)

URL:              https://$APP_HOST
MASTER_PASSWORD=$MASTER_PASSWORD
DB_PASSWORD=$DB_PASSWORD

MASTER_PASSWORD gates every page and the API (X-Desk-Token header or ?token=).
DB_PASSWORD is the local 'shoemoneyx' MariaDB user, loopback-only.
EOF
chmod 600 "$CREDS_FILE"

touch "$MARKER"
chown "$APP_USER":"$APP_USER" "$MARKER"
log "first boot complete"
