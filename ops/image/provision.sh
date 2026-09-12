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
  ca-certificates curl gnupg git ufw openssl certbot

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

log "cloning $REPO_URL@v$VERSION into $APP_DIR (compose file + docker/up.sh + .env.example; app itself runs from the ghcr.io images)"
rm -rf "$APP_DIR"
git clone --depth 1 --branch "v$VERSION" "$REPO_URL" "$APP_DIR"
echo "$VERSION" > /opt/shoemoneyx-version

log "pulling pinned images (ghcr.io/shoemoney/shoemoneyx(-nginx):$VERSION) so first boot needs no registry round trip"
cd "$APP_DIR"
SHOEMONEYX_VERSION="$VERSION" docker compose pull

log "installing first-boot + firewall scripts and their systemd units"
install -m 0700 /tmp/image-files/first-boot.sh /opt/shoemoneyx-first-boot.sh
install -m 0700 /tmp/image-files/firewall.sh /opt/shoemoneyx-firewall.sh
install -m 0644 /tmp/image-files/systemd/shoemoneyx-first-boot.service /etc/systemd/system/shoemoneyx-first-boot.service
install -m 0644 /tmp/image-files/systemd/shoemoneyx-firewall.service /etc/systemd/system/shoemoneyx-firewall.service

log "enabling services"
systemctl enable shoemoneyx-first-boot.service
systemctl enable shoemoneyx-firewall.service

log "cleaning apt caches"
apt-get clean
rm -rf /var/lib/apt/lists/* /tmp/image-files

log "firewall LAST: owns both the ufw host policy and the Docker egress rules (see firewall.sh)"
/opt/shoemoneyx-firewall.sh

log "provisioning complete"
