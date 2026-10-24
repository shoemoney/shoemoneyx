#!/usr/bin/env bash
# /opt/shoemoneyx-first-boot.sh — runs once via shoemoneyx-first-boot.service.
# Secret generation and the DB migration are no longer this script's job: they belong to
# docker/up.sh (identical to what a self-hoster runs by hand) and to the compose file's own
# one-shot `migrate` service. This script pins the image version, hands off to up.sh, gets a trusted
# cert via acme.sh (before the stack starts), and writes the credentials file the same way the desk
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

# The bootstrap password is the instance ID: the buyer reads it in the AWS console, so the
# Marketplace one-click launch needs no SSH. It is a one-time key: the app shows the hint on /login,
# and signing in with it only opens the set-password screen where the buyer chooses their own.
INSTANCE_ID="$(imds instance-id)"
# The region only builds the login page's "open my instances" console link; the instance ID itself
# is never put in a URL or rendered anywhere but the buyer's own AWS console.
AWS_REGION="$(imds placement/region)"
[[ "$AWS_REGION" =~ ^[a-z]{2}(-[a-z]+)+-[0-9]+$ ]] || AWS_REGION=""
# Browser-trusted cert BEFORE the stack (and so nginx) comes up: acme.sh opens inbound 80 for the
# HTTP-01 challenge, closes it again no matter how it exits, and seeds the compose tls-data volume
# so nginx's first start already serves it. Any failure (no public IP, rate limit, network, the
# security group not allowing 80) leaves nginx generating its per-install self-signed cert.
log "requesting a browser-trusted certificate (Let's Encrypt, IP address or DOMAIN tag)"
# renew, not issue: a retried first boot (up.sh failing) must not burn a second issuance against
# the rate limit; renew is a no-op once a good cert is installed.
if /opt/shoemoneyx-acme.sh renew; then
  log "trusted certificate installed"
else
  log "certificate issuance failed — nginx will serve its per-install self-signed cert; shoemoneyx-acme.timer keeps retrying (see docs/HOSTED_IMAGE.md troubleshooting)"
fi
log "handing off to docker/up.sh — generates .env, runs the migrate gate, brings up the stack, and blocks until /api/status answers"
SHOEMONEYX_VERSION="$VERSION" \
MASTER_PASSWORD="${INSTANCE_ID:-}" \
DESK_AWS_REGION="$AWS_REGION" \
DESK_FIRST_LOGIN_GUIDE="aws" \
MASTER_PASSWORD_HINT="First login uses your EC2 instance ID (i-…, shown in the AWS console) once; you will be asked to choose your own password" \
  ./docker/up.sh

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

log "writing credentials to $CREDS_FILE"
MASTER_PASSWORD="$(grep '^MASTER_PASSWORD=' .env | cut -d= -f2-)"
DB_PASSWORD="$(grep '^DB_PASSWORD=' .env | cut -d= -f2-)"
cat > "$CREDS_FILE" <<EOF
shoemoneyx desk — first-boot credentials
generated: $(date -u +%FT%TZ)

URL:              https://$APP_HOST
MASTER_PASSWORD=$MASTER_PASSWORD
DB_PASSWORD=$DB_PASSWORD

MASTER_PASSWORD is a one-time first-login key (this instance's ID): log in at /login with it once and you
will be asked to choose your own password. After that it no longer opens anything.
DB_PASSWORD is the local 'shoemoneyx' MariaDB user, reachable only from the compose network.
EOF
chmod 600 "$CREDS_FILE"

log "first boot complete"
