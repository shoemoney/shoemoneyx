#!/usr/bin/env bash
# /opt/shoemoneyx-first-boot.sh — runs once via shoemoneyx-first-boot.service.
# Secret generation and the DB migration are no longer this script's job: they belong to
# docker/up.sh (identical to what a self-hoster runs by hand) and to the compose file's own
# one-shot `migrate` service. This script pins the image version, hands off to up.sh, swaps in a
# real cert when a DOMAIN tag is present, and writes the credentials file the same way the desk
# always has. It deliberately never recreates web/queue/schedule/reverb/desk after up.sh brings
# them up: nginx resolves the `web`/`reverb` hostnames once at its own startup and never again
# (no `resolver` directive), so recreating one of those containers behind an nginx that's still
# running leaves nginx pointing at a dead IP — a real 502 reproduced while writing this. up.sh's
# own APP_URL=https://localhost is left as-is (the same default every self-hoster runs on); the
# credentials file below reports the box's real IP/domain as the URL to open without touching
# .env or the running stack.
set -euo pipefail

APP_DIR=/opt/shoemoneyx
CREDS_FILE=/root/shoemoneyx-credentials.txt
VERSION="$(cat /opt/shoemoneyx-version)"

log() { echo "[first-boot] $*"; }

cd "$APP_DIR"

if [[ -f .env ]]; then
  log ".env already exists, first boot already ran — skipping"
  exit 0
fi

TOKEN_MD="http://169.254.169.254/latest/api/token"
META="http://169.254.169.254/latest/meta-data"
IMDS_TOKEN="$(curl -s -X PUT "$TOKEN_MD" -H 'X-aws-ec2-metadata-token-ttl-seconds: 60' || true)"
imds() { curl -s -H "X-aws-ec2-metadata-token: $IMDS_TOKEN" "$META/$1" 2>/dev/null || true; }

PUBLIC_IP="$(imds public-ipv4)"
[[ -z "$PUBLIC_IP" ]] && PUBLIC_IP="$(imds local-ipv4)"

# DOMAIN comes from the instance's own tags via IMDS (no IAM role, no AWS CLI, no outbound EC2
# API call needed — keeps this working under the outbound-443-only firewall). Requires the
# instance be launched with --metadata-options InstanceMetadataTags=enabled; if it wasn't, this
# is empty and we fall back to the self-signed cert baked into the nginx image, the safe default.
DOMAIN="$(imds tags/instance/DOMAIN)"
[[ "$DOMAIN" == *"Not Found"* || "$DOMAIN" == *"404"* ]] && DOMAIN=""
APP_HOST="${DOMAIN:-$PUBLIC_IP}"
log "app host: $APP_HOST (domain tag: ${DOMAIN:-none}), pinning SHOEMONEYX_VERSION=$VERSION"

log "handing off to docker/up.sh — generates .env, runs the migrate gate, brings up the stack, and blocks until /api/status answers"
SHOEMONEYX_VERSION="$VERSION" ./docker/up.sh

# Pin the version in .env too (up.sh only sees it on the process environment) so a later manual
# `docker compose pull` on this box stays on this release by default. A plain text edit to .env
# — no container touches this file again until something is next recreated, so there's no need
# to restart anything for it to take effect.
log "pinning SHOEMONEYX_VERSION=$VERSION in .env for future manual docker compose runs"
if grep -q '^SHOEMONEYX_VERSION=' .env; then
  sed -i "s/^SHOEMONEYX_VERSION=.*/SHOEMONEYX_VERSION=$VERSION/" .env
else
  echo "SHOEMONEYX_VERSION=$VERSION" >> .env
fi

if [[ -n "$DOMAIN" ]]; then
  log "DOMAIN tag present ($DOMAIN): requesting a real cert via certbot (standalone, port 80 briefly)"
  ufw allow in 80/tcp
  if certbot certonly --standalone --non-interactive --agree-tos -m "admin@$DOMAIN" -d "$DOMAIN"; then
    cat > docker-compose.override.yml <<YAML
services:
  nginx:
    volumes:
      - /etc/letsencrypt/live/$DOMAIN/fullchain.pem:/etc/ssl/shoemoneyx/selfsigned.crt:ro
      - /etc/letsencrypt/live/$DOMAIN/privkey.pem:/etc/ssl/shoemoneyx/selfsigned.key:ro
YAML
    docker compose up -d nginx
    log "certbot succeeded for $DOMAIN, nginx now serving the real cert"
  else
    log "certbot failed for $DOMAIN (port 80 must be reachable in the SG for HTTP-01), staying on the self-signed cert"
  fi
  ufw delete allow in 80/tcp
else
  log "no DOMAIN tag, staying on the image's build-time self-signed cert"
fi

log "writing credentials to $CREDS_FILE"
MASTER_PASSWORD="$(grep '^MASTER_PASSWORD=' .env | cut -d= -f2-)"
DB_PASSWORD="$(grep '^DB_PASSWORD=' .env | cut -d= -f2-)"
cat > "$CREDS_FILE" <<EOF
shoemoneyx desk — first-boot credentials
generated: $(date -u +%FT%TZ)

URL:              https://$APP_HOST
MASTER_PASSWORD=$MASTER_PASSWORD
DB_PASSWORD=$DB_PASSWORD

MASTER_PASSWORD gates every page and the API (X-Desk-Token header or ?token=).
DB_PASSWORD is the local 'shoemoneyx' MariaDB user, reachable only from the compose network.
EOF
chmod 600 "$CREDS_FILE"

log "first boot complete"
