#!/usr/bin/env bash
# /opt/shoemoneyx-firewall.sh — installed by provision.sh, run once at the end of
# provisioning and again on every boot via shoemoneyx-firewall.service (After=docker.service).
# Docker rewrites iptables itself whenever dockerd starts, and none of it survives a reboot
# anyway, so the whole policy has to be reapplied every boot — both halves of it, in one file,
# so "outbound 443+53 only" can't drift between the host and the containers:
#
#   1. ufw: the host-level policy (inbound 22+443, outbound 443+53, the IMDS exception).
#   2. DOCKER-USER: container-originated traffic is forwarded, not routed through ufw's own
#      OUTPUT chain, and Docker's own rules in FORWARD accept it before ufw ever sees it — so
#      without this, "outbound 443+53 only" silently stops applying the moment the app runs in
#      containers. DOCKER-USER is the chain Docker reserves for user rules and never touches.
set -euo pipefail

log() { echo "[firewall] $*"; }

log "ufw: inbound 22+443 only, outbound 443+53 only, deny the rest"
ufw --force reset
ufw default deny incoming
ufw default deny outgoing
ufw allow in 22/tcp
ufw allow in 443/tcp
ufw allow out 443/tcp
ufw allow out 53
# EC2 instance metadata (169.254.169.254) is always plain HTTP on port 80 — a link-local
# hypervisor service, not "outbound to the internet". Without this, cloud-init can't fetch the
# SSH host key material and boots with sshd unreachable, and first-boot.sh can't read the
# instance's public IP / DOMAIN tag. Scoped to this one address only.
ufw allow out to 169.254.169.254 port 80 proto tcp
ufw logging off
ufw --force enable

DEFAULT_IFACE="$(ip route show default | awk '{for (i=1;i<=NF;i++) if ($i=="dev") {print $(i+1); exit}}')"
if [[ -z "$DEFAULT_IFACE" ]]; then
  log "WARNING: no default route found, can't scope DOCKER-USER to an egress interface — ufw is still in place, skipping the Docker chain"
  exit 0
fi
log "default egress interface: $DEFAULT_IFACE"

# The compose network isn't guaranteed to exist the instant docker.service comes up — its
# containers restart asynchronously — so give it a short window. shoemoneyx-firewall.service
# also orders After= the first-boot service (which brings the network up on a brand-new
# instance), so this loop is a defensive backstop, not the primary mechanism.
BRIDGE_SUBNETS=""
for _ in $(seq 1 15); do
  BRIDGE_SUBNETS="$(docker network ls --filter driver=bridge -q 2>/dev/null \
    | xargs -r -I{} docker network inspect -f '{{range .IPAM.Config}}{{.Subnet}} {{end}}' {} 2>/dev/null \
    | tr -s ' ' '\n' | grep -v '^$' | sort -u)"
  [[ -n "$BRIDGE_SUBNETS" ]] && break
  sleep 2
done

iptables -nL DOCKER-USER >/dev/null 2>&1 || iptables -N DOCKER-USER
iptables -F DOCKER-USER
iptables -A DOCKER-USER -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN

if [[ -z "$BRIDGE_SUBNETS" ]]; then
  log "no Docker bridge networks found yet — leaving DOCKER-USER at established/related-only until this reruns with one present"
else
  while read -r SUBNET; do
    [[ -z "$SUBNET" ]] && continue
    log "allowing $SUBNET -> 443/53 + IMDS, out $DEFAULT_IFACE"
    iptables -A DOCKER-USER -s "$SUBNET" -o "$DEFAULT_IFACE" -p tcp --dport 443 -j RETURN
    iptables -A DOCKER-USER -s "$SUBNET" -o "$DEFAULT_IFACE" -p tcp --dport 53 -j RETURN
    iptables -A DOCKER-USER -s "$SUBNET" -o "$DEFAULT_IFACE" -p udp --dport 53 -j RETURN
    iptables -A DOCKER-USER -s "$SUBNET" -d 169.254.169.254 -o "$DEFAULT_IFACE" -p tcp --dport 80 -j RETURN
    # Only traffic leaving via the host's real egress interface is policed here: bridge-to-bridge
    # traffic (web -> mariadb, nginx -> web) has an -o of the bridge device, never $DEFAULT_IFACE,
    # so it never matches this DROP and container-to-container traffic is untouched.
    iptables -A DOCKER-USER -s "$SUBNET" -o "$DEFAULT_IFACE" -j DROP
  done <<< "$BRIDGE_SUBNETS"
fi

# Anything unmatched (bridge-to-bridge, inbound DNAT to the published 443 — Docker's DNAT lands
# before ufw INPUT and before this chain sees the packet's real destination) falls through to
# Docker's own chains unchanged; a plain user-chain fallthrough already does this, the explicit
# RETURN just documents it.
iptables -A DOCKER-USER -j RETURN

log "firewall policy applied"
