#!/usr/bin/env bash
# ops/image/provision.sh — runs as root on the Packer build instance (Ubuntu 24.04).
# Installs everything the desk needs, clones + builds the app at build time, lays
# down systemd units and the first-boot hook, then locks the box down with ufw
# LAST so outbound package/git/npm/composer fetches during this script aren't
# blocked by the very firewall it bakes in.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

APP_DIR=/opt/shoemoneyx
APP_USER=shoemoneyx
REPO_URL="https://github.com/shoemoney/shoemoneyx"
REPO_REF="main"

log() { echo "[provision] $*"; }

log "apt update + base packages"
apt-get update -y
apt-get upgrade -y
apt-get install -y --no-install-recommends \
  software-properties-common ca-certificates curl gnupg lsb-release unzip git \
  ufw openssl nginx redis-server mariadb-server mariadb-client certbot python3-certbot-nginx

log "adding ondrej/php PPA for PHP 8.4"
add-apt-repository -y ppa:ondrej/php
apt-get update -y
apt-get install -y --no-install-recommends \
  php8.4 php8.4-fpm php8.4-cli php8.4-mysql php8.4-redis php8.4-bcmath php8.4-gmp \
  php8.4-intl php8.4-zip php8.4-mbstring php8.4-xml php8.4-curl php8.4-gd php8.4-opcache
# sodium ships built into core PHP since 7.2 (ondrej/php builds it in, no php8.4-sodium package exists)

log "opcache / cli tuning (backtests are a hot loop over the same classes)"
cat > /etc/php/8.4/fpm/conf.d/99-shoemoneyx.ini <<'EOF'
opcache.enable=1
opcache.jit=tracing
opcache.jit_buffer_size=128M
memory_limit=1G
EOF
cp /etc/php/8.4/fpm/conf.d/99-shoemoneyx.ini /etc/php/8.4/cli/conf.d/99-shoemoneyx.ini

log "installing Node LTS via NodeSource"
curl -fsSL https://deb.nodesource.com/setup_lts.x | bash -
apt-get install -y --no-install-recommends nodejs

log "installing Composer"
php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm -f /tmp/composer-setup.php

log "creating system user $APP_USER"
id -u "$APP_USER" >/dev/null 2>&1 || useradd --system --create-home --home-dir "$APP_DIR" --shell /usr/sbin/nologin "$APP_USER"

log "cloning $REPO_URL@$REPO_REF into $APP_DIR"
rm -rf "$APP_DIR"
git clone --depth 1 --branch "$REPO_REF" "$REPO_URL" "$APP_DIR"
chown -R "$APP_USER":"$APP_USER" "$APP_DIR"

log "composer install (no dev, no scripts until .env exists)"
cd "$APP_DIR"
sudo -u "$APP_USER" composer install --no-dev --no-interaction --no-scripts --optimize-autoloader
sudo -u "$APP_USER" composer dump-autoload --optimize --no-dev

log "placing Font Awesome Pro tarballs (licensed, uploaded by the file provisioner)"
cp -r /tmp/fa-pro "$APP_DIR/.fa-pro"
chown -R "$APP_USER":"$APP_USER" "$APP_DIR/.fa-pro"

log "npm install + build (frontend only needs build output, not baked secrets)"
sudo -u "$APP_USER" npm ci --no-audit --no-fund
sudo -u "$APP_USER" npm run build
rm -rf "$APP_DIR/node_modules" "$APP_DIR/.fa-pro"

log "storage/bootstrap cache perms"
mkdir -p "$APP_DIR"/storage/logs "$APP_DIR"/storage/framework/{cache,sessions,views} "$APP_DIR"/bootstrap/cache
chown -R "$APP_USER":"$APP_USER" "$APP_DIR"/storage "$APP_DIR"/bootstrap/cache
chmod -R 775 "$APP_DIR"/storage "$APP_DIR"/bootstrap/cache

log "PHP-FPM pool runs as $APP_USER"
sed -i "s/^user = .*/user = $APP_USER/; s/^group = .*/group = $APP_USER/; s/^listen.owner = .*/listen.owner = www-data/; s/^listen.group = .*/listen.group = www-data/" /etc/php/8.4/fpm/pool.d/www.conf

log "self-signed cert at build time (first boot replaces it via certbot if DOMAIN tag is set)"
mkdir -p /etc/ssl/shoemoneyx
openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
  -keyout /etc/ssl/shoemoneyx/selfsigned.key \
  -out /etc/ssl/shoemoneyx/selfsigned.crt \
  -subj "/CN=shoemoneyx-desk"
chmod 600 /etc/ssl/shoemoneyx/selfsigned.key

log "nginx site"
cp /tmp/image-files/nginx-shoemoneyx.conf /etc/nginx/sites-available/shoemoneyx
rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/shoemoneyx /etc/nginx/sites-enabled/shoemoneyx

log "systemd units"
install -m 0644 /tmp/image-files/systemd/shoemoneyx-desk.service /etc/systemd/system/shoemoneyx-desk.service
install -m 0644 /tmp/image-files/systemd/shoemoneyx-queue.service /etc/systemd/system/shoemoneyx-queue.service
install -m 0644 /tmp/image-files/systemd/shoemoneyx-schedule.service /etc/systemd/system/shoemoneyx-schedule.service
install -m 0644 /tmp/image-files/systemd/shoemoneyx-reverb.service /etc/systemd/system/shoemoneyx-reverb.service
install -m 0644 /tmp/image-files/systemd/shoemoneyx-first-boot.service /etc/systemd/system/shoemoneyx-first-boot.service

log "first-boot script"
install -m 0700 /tmp/image-files/first-boot.sh /opt/shoemoneyx-first-boot.sh

log "enabling services (desk/queue/schedule/reverb start after first-boot completes; see the unit's After=/Requires=)"
systemctl enable nginx php8.4-fpm mariadb redis-server
systemctl enable shoemoneyx-first-boot.service
systemctl enable shoemoneyx-desk.service shoemoneyx-queue.service shoemoneyx-schedule.service shoemoneyx-reverb.service

log "cleaning apt caches"
apt-get clean
rm -rf /var/lib/apt/lists/* /tmp/image-files /tmp/composer-setup.php

log "ufw LAST: inbound 22+443 only, outbound 443+53 only, deny the rest"
ufw --force reset
ufw default deny incoming
ufw default deny outgoing
ufw allow in 22/tcp
ufw allow in 443/tcp
ufw allow out 443/tcp
ufw allow out 53
# EC2 instance metadata (169.254.169.254) is always plain HTTP on port 80 — a link-local
# hypervisor service, not "outbound to the internet". Without this, cloud-init can't fetch
# the SSH host key material and boots with sshd unreachable, and first-boot.sh can't read
# the instance's public IP / DOMAIN tag. Scoped to this one address only; port 80 stays
# closed to everywhere else, keeping the "443+53 only" outbound policy intact otherwise.
ufw allow out to 169.254.169.254 port 80 proto tcp
ufw logging off
ufw --force enable

log "provisioning complete"
