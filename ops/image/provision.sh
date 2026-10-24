#!/usr/bin/env bash
# ops/image/provision.sh — runs as root on the Packer build instance (Ubuntu 24.04).
# Installs Docker, clones the shoemoneyx repo at the pinned release tag for its compose file
# and scripts (the app itself runs from the published ghcr.io images, not built here), pulls
# those images so first boot needs no registry round trip, lays down the first-boot and
# firewall systemd units, then locks the box down with the firewall script LAST so the pull
# above isn't blocked by the very policy it bakes in.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

VERSION="${VERSION:?VERSION env var required, set by desk.pkr.hcl shell provisioner environment_vars from -var version=...}"
APP_DIR=/opt/shoemoneyx
REPO_URL="https://github.com/shoemoney/shoemoneyx"

log() { echo "[provision] $*"; }

log "apt update + base packages"
apt-get update -y
apt-get upgrade -y
apt-get install -y --no-install-recommends \
  ca-certificates curl gnupg git ufw openssl python3-venv chrony

log "2 GB swapfile (t3.small has 2 GB RAM and no swap; docker-compose.yml memswap_limit relies on it)"
if ! swapon --show=NAME --noheadings | grep -q '^/swapfile$'; then
  rm -f /swapfile
  fallocate -l 2G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile
  swapon /swapfile
fi
grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
echo 'vm.swappiness=10' > /etc/sysctl.d/99-shoemoneyx-swap.conf
sysctl -q -p /etc/sysctl.d/99-shoemoneyx-swap.conf

log "installing Docker Engine + compose plugin from Docker's own apt repo"
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
ARCH="$(dpkg --print-architecture)"
CODENAME="$(. /etc/os-release && echo "$VERSION_CODENAME")"
echo "deb [arch=$ARCH signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $CODENAME stable" \
  > /etc/apt/sources.list.d/docker.list
apt-get update -y
apt-get install -y --no-install-recommends docker-ce docker-ce-cli containerd.io docker-compose-plugin
systemctl enable docker

# The firewall only lets UDP 123 reach Amazon Time Sync (169.254.169.123), so that must be chrony's
# source: public NTP pools would just time out. The pool lines are commented out of the packaged config.
log "pointing chrony at the Amazon Time Sync Service"
mkdir -p /etc/chrony/sources.d
echo "server 169.254.169.123 prefer iburst minpoll 4 maxpoll 4" > /etc/chrony/sources.d/aws-time-sync.sources
sed -i 's/^pool /#pool /' /etc/chrony/chrony.conf
grep -q '^sourcedir /etc/chrony/sources.d' /etc/chrony/chrony.conf || echo "sourcedir /etc/chrony/sources.d" >> /etc/chrony/chrony.conf
systemctl enable chrony

# certbot from PyPI, not apt: Let's Encrypt IP-address certificates need certbot >= 5.4
# (--ip-address with standalone/webroot) and Ubuntu 24.04's apt package is far older. Only the
# program is installed here; no account, key or cert exists until first boot, on the instance.
log "installing certbot >= 5.4 into /opt/certbot"
python3 -m venv /opt/certbot
# Exact versions and PyPI artifact hashes cover the full dependency closure. Wheels only
# prevent source builds from fetching unpinned build dependencies (bump procedure in the file).
/opt/certbot/bin/pip install --no-cache-dir --require-hashes --only-binary=:all: \
  -r /tmp/image-files/certbot-requirements.txt
/opt/certbot/bin/python - <<'PY'
import sys
from importlib.metadata import version
v = tuple(int(x) for x in version("certbot").split(".")[:2])
sys.exit(0 if v >= (5, 4) else 1)
PY
ln -sf /opt/certbot/bin/certbot /usr/local/bin/certbot
certbot --version

log "cloning $REPO_URL@v$VERSION into $APP_DIR (compose file + docker/up.sh + .env.example; app itself runs from the ghcr.io images)"
rm -rf "$APP_DIR"
git clone --depth 1 --branch "v$VERSION" "$REPO_URL" "$APP_DIR"
echo "$VERSION" > /opt/shoemoneyx-version

log "pulling pinned images (ghcr.io/shoemoney/shoemoneyx(-nginx):$VERSION) so first boot needs no registry round trip"
cd "$APP_DIR"
SHOEMONEYX_VERSION="$VERSION" docker compose pull

log "installing first-boot + firewall + acme scripts and their systemd units"
install -m 0700 /tmp/image-files/first-boot.sh /opt/shoemoneyx-first-boot.sh
install -m 0700 /tmp/image-files/firewall.sh /opt/shoemoneyx-firewall.sh
install -m 0700 /tmp/image-files/acme.sh /opt/shoemoneyx-acme.sh
install -m 0644 /tmp/image-files/systemd/shoemoneyx-first-boot.service /etc/systemd/system/shoemoneyx-first-boot.service
install -m 0644 /tmp/image-files/systemd/shoemoneyx-firewall.service /etc/systemd/system/shoemoneyx-firewall.service
install -m 0644 /tmp/image-files/systemd/shoemoneyx-acme.service /etc/systemd/system/shoemoneyx-acme.service
install -m 0644 /tmp/image-files/systemd/shoemoneyx-acme.timer /etc/systemd/system/shoemoneyx-acme.timer

log "enabling services"
systemctl enable shoemoneyx-first-boot.service
systemctl enable shoemoneyx-firewall.service
systemctl enable shoemoneyx-acme.service shoemoneyx-acme.timer

log "cleaning apt caches"
apt-get clean
rm -rf /var/lib/apt/lists/* /tmp/image-files

log "firewall LAST: owns both the ufw host policy and the Docker egress rules (see firewall.sh)"
/opt/shoemoneyx-firewall.sh

log "making sure no ACME account, key or certificate state is in the image (all of it is per-instance, created at first boot)"
rm -rf /etc/letsencrypt /var/lib/letsencrypt /var/log/letsencrypt /var/lib/shoemoneyx

log "removing build-time SSH keys and shell history (AWS Marketplace scan rejects baked-in authorized_keys; cloud-init re-installs the launch key pair at boot)"
shred -u /root/.ssh/authorized_keys /home/ubuntu/.ssh/authorized_keys 2>/dev/null || rm -f /root/.ssh/authorized_keys /home/ubuntu/.ssh/authorized_keys
rm -f /root/.bash_history /home/ubuntu/.bash_history

log "clearing machine identity so every buyer's instance gets its own (machine-id, cloud-init state and logs)"
truncate -s0 /etc/machine-id
rm -f /var/lib/dbus/machine-id
cloud-init clean --logs

log "provisioning complete"
