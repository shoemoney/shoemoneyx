#!/usr/bin/env bash
# ops/image/test-boot.sh <ami-id>
#
# Boots one t3.small from the given AMI in the default VPC behind a temporary
# security group (22 from the office IP only, 443 and 80 from anywhere — 80 only so Let's Encrypt
# can reach the HTTP-01 challenge; the instance itself keeps it closed), then proves the desk image
# works the way a customer's box must:
#   1. HTTPS comes up within 10 minutes
#   2. the certificate is publicly trusted for the instance's IP (curl WITHOUT -k succeeds, the
#      issuer is Let's Encrypt, the IP is in the SAN)
#   3. GET /api/status returns 200 with "mode":"paper"
#   4. the clock is synchronized to Amazon Time Sync (chrony; outbound UDP 123 to 169.254.169.123)
#   5. nmap of the low 1024 ports shows nothing open except 22 and 443, and inbound 80 is closed
#      again (ufw has no rule for it, and a plain http request gets no answer)
# Terminates the instance and deletes the security group on the way out,
# success or failure. Exits non-zero on any assertion failure.
set -euo pipefail
# Credential configs, payloads and response bodies must never be world-readable.
umask 077

AMI_ID="${1:-}"
if [[ -z "$AMI_ID" ]]; then
  echo "usage: $0 <ami-id>" >&2
  exit 2
fi

REGION="${AWS_REGION:-us-east-1}"
PROFILE="${AWS_PROFILE:-default}"
OFFICE_CIDR="68.185.216.69/32"
INSTANCE_TYPE="t3.small"
KEY_NAME="${DESK_TEST_KEY_NAME:-smx}"
SSH_KEY="${DESK_TEST_SSH_KEY:-$HOME/.ssh/smx.pem}"
BOOT_TIMEOUT_SECS=600
POLL_INTERVAL_SECS=10

aws_() { aws --profile "$PROFILE" --region "$REGION" "$@"; }
# LogLevel=ERROR suppresses ssh's "Warning: Permanently added ... to the list of known hosts"
# line, which UserKnownHostsFile=/dev/null makes it print on every single connection — without
# this, that line becomes stdout line 1 of any output this function's caller parses positionally.
ssh_() { ssh -i "$SSH_KEY" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -o ConnectTimeout=5 "ubuntu@$PUBLIC_IP" "$@"; }

INSTANCE_ID=""
SG_ID=""
FAILED=0
AUTH_DIR=""

log() { echo "[test-boot] $*" >&2; }

cleanup() {
  if [[ -n "$AUTH_DIR" ]]; then
    rm -rf -- "$AUTH_DIR"
  fi
  if [[ -n "$INSTANCE_ID" ]]; then
    log "terminating $INSTANCE_ID"
    aws_ ec2 terminate-instances --instance-ids "$INSTANCE_ID" >/dev/null 2>&1 || true
    aws_ ec2 wait instance-terminated --instance-ids "$INSTANCE_ID" >/dev/null 2>&1 || true
  fi
  if [[ -n "$SG_ID" ]]; then
    log "deleting security group $SG_ID"
    # SG deletion can race the ENI detach right after termination; retry briefly.
    for _ in 1 2 3 4 5 6; do
      aws_ ec2 delete-security-group --group-id "$SG_ID" >/dev/null 2>&1 && break
      sleep 5
    done
  fi
}
trap cleanup EXIT

fail() {
  log "FAIL: $*"
  FAILED=1
}

log "resolving default VPC in $REGION"
VPC_ID="$(aws_ ec2 describe-vpcs --filters Name=isDefault,Values=true --query 'Vpcs[0].VpcId' --output text)"
if [[ -z "$VPC_ID" || "$VPC_ID" == "None" ]]; then
  echo "no default VPC found in $REGION" >&2
  exit 1
fi
SUPPORTED_AZS="$(aws_ ec2 describe-instance-type-offerings --location-type availability-zone \
  --filters "Name=instance-type,Values=$INSTANCE_TYPE" --query 'InstanceTypeOfferings[].Location' --output text)"
SUBNET_ID=""
for az in $SUPPORTED_AZS; do
  SUBNET_ID="$(aws_ ec2 describe-subnets --filters "Name=vpc-id,Values=$VPC_ID" "Name=default-for-az,Values=true" "Name=availability-zone,Values=$az" --query 'Subnets[0].SubnetId' --output text)"
  [[ -n "$SUBNET_ID" && "$SUBNET_ID" != "None" ]] && break
done
if [[ -z "$SUBNET_ID" || "$SUBNET_ID" == "None" ]]; then
  echo "no default subnet found in an AZ that supports $INSTANCE_TYPE" >&2
  exit 1
fi
log "VPC $VPC_ID subnet $SUBNET_ID"

SG_NAME="shoemoneyx-desk-boot-test-$$-$(date +%s)"
log "creating temp security group $SG_NAME"
SG_ID="$(aws_ ec2 create-security-group --group-name "$SG_NAME" --description "shoemoneyx desk image boot test (temporary)" --vpc-id "$VPC_ID" --query 'GroupId' --output text)"
aws_ ec2 authorize-security-group-ingress --group-id "$SG_ID" --ip-permissions \
  "IpProtocol=tcp,FromPort=22,ToPort=22,IpRanges=[{CidrIp=$OFFICE_CIDR,Description='office ssh'}]" \
  "IpProtocol=tcp,FromPort=443,ToPort=443,IpRanges=[{CidrIp=0.0.0.0/0,Description='https'}]" \
  "IpProtocol=tcp,FromPort=80,ToPort=80,IpRanges=[{CidrIp=0.0.0.0/0,Description='acme http-01'}]" >/dev/null

log "launching $INSTANCE_TYPE from $AMI_ID"
INSTANCE_ID="$(aws_ ec2 run-instances \
  --image-id "$AMI_ID" \
  --instance-type "$INSTANCE_TYPE" \
  --key-name "$KEY_NAME" \
  --subnet-id "$SUBNET_ID" \
  --security-group-ids "$SG_ID" \
  --associate-public-ip-address \
  --metadata-options "InstanceMetadataTags=enabled,HttpTokens=required" \
  --tag-specifications "ResourceType=instance,Tags=[{Key=Name,Value=$SG_NAME}]" \
  --query 'Instances[0].InstanceId' --output text)"
log "instance $INSTANCE_ID, waiting for running"
aws_ ec2 wait instance-running --instance-ids "$INSTANCE_ID"

PUBLIC_IP="$(aws_ ec2 describe-instances --instance-ids "$INSTANCE_ID" --query 'Reservations[0].Instances[0].PublicIpAddress' --output text)"
if [[ -z "$PUBLIC_IP" || "$PUBLIC_IP" == "None" ]]; then
  echo "instance has no public IP" >&2
  exit 1
fi
log "public IP $PUBLIC_IP"

log "waiting up to ${BOOT_TIMEOUT_SECS}s for :443"
UP=0
DEADLINE=$((SECONDS + BOOT_TIMEOUT_SECS))
while (( SECONDS < DEADLINE )); do
  if curl -k -s -o /dev/null -m 5 "https://$PUBLIC_IP/api/status" 2>/dev/null; then
    UP=1
    break
  fi
  sleep "$POLL_INTERVAL_SECS"
done
if [[ "$UP" -ne 1 ]]; then
  fail ":443 never answered within ${BOOT_TIMEOUT_SECS}s"
fi

PUBLIC_TRUST_READY=0
if [[ "$UP" -eq 1 ]]; then
  # Issuance runs before the stack starts, so a trusted cert is normally there the moment :443
  # answers; the retry window only absorbs a slow Let's Encrypt round trip.
  log "asserting the certificate is publicly trusted for $PUBLIC_IP (curl without -k)"
  TRUSTED=0
  TRUST_DEADLINE=$((SECONDS + 180))
  while (( SECONDS < TRUST_DEADLINE )); do
    if curl -s -o /dev/null -m 10 --fail "https://$PUBLIC_IP/login" 2>/dev/null; then
      TRUSTED=1
      break
    fi
    sleep "$POLL_INTERVAL_SECS"
  done
  CERT_TEXT="$(openssl s_client -connect "$PUBLIC_IP:443" </dev/null 2>/dev/null | openssl x509 -noout -issuer -text 2>/dev/null || true)"
  if [[ "$TRUSTED" -ne 1 ]]; then
    fail "https://$PUBLIC_IP/login without -k never succeeded: the cert is not publicly trusted"
  elif ! grep -qi "issuer=.*Let's Encrypt" <<<"$CERT_TEXT"; then
    fail "certificate issuer is not Let's Encrypt: $(grep -i '^issuer=' <<<"$CERT_TEXT")"
  elif ! grep -Eq "IP Address:${PUBLIC_IP//./\\.}(,|$)" <<<"$CERT_TEXT"; then
    fail "certificate has no IP Address:$PUBLIC_IP SAN"
  else
    log "OK: publicly trusted Let's Encrypt certificate covering $PUBLIC_IP"
    if ! LOGIN_HTML="$(curl -s --fail -m 10 "https://$PUBLIC_IP/login")"; then
      fail "GET https://$PUBLIC_IP/login failed while checking for the certificate warning banner"
    elif ! grep -q 'id="first-login-guide"' <<<"$LOGIN_HTML"; then
      fail "login page is not in AWS first-login mode (no id=first-login-guide), so the banner check would be vacuous"
    elif grep -q 'id="tls-warning"' <<<"$LOGIN_HTML"; then
      fail "login page shows the missing-certificate warning although the certificate is trusted (tls-status volume not read?)"
    else
      PUBLIC_TRUST_READY=1
      log "OK: first-login page shows no certificate warning"
    fi
  fi
fi

MASTER_PASSWORD=""
if [[ "${PUBLIC_TRUST_READY:-0}" -eq 1 ]]; then
  log "fetching MASTER_PASSWORD via ssh (retrying while cloud-init finishes)"
  for _ in $(seq 1 30); do
    MASTER_PASSWORD="$(ssh -i "$SSH_KEY" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
      -o ConnectTimeout=5 "ubuntu@$PUBLIC_IP" \
      "sudo cat /root/shoemoneyx-credentials.txt" 2>/dev/null | grep -m1 -oE '^MASTER_PASSWORD=.*' | cut -d= -f2- || true)"
    [[ -n "$MASTER_PASSWORD" ]] && break
    sleep 10
  done
  if [[ -z "$MASTER_PASSWORD" ]]; then
    fail "could not read MASTER_PASSWORD from /root/shoemoneyx-credentials.txt over ssh"
  fi
fi

# These credentials belong only to the disposable instance launched above. All authenticated
# requests use normal curl TLS verification, independently of the earlier certificate checks.
# Keep secrets in private files, never command arguments or failure response logs.
verify_auth_lifecycle() {
  AUTH_DIR="$(mktemp -d)" || return 1
  local bootstrap_config="$AUTH_DIR/bootstrap.curl" owner_config="$AUTH_DIR/owner.curl"
  local payload="$AUTH_DIR/claim.json" body="$AUTH_DIR/body" owner_password code
  # Reject header injection before encoding the bootstrap credential as a curl config value.
  [[ "$MASTER_PASSWORD" != *$'\r'* && "$MASTER_PASSWORD" != *$'\n'* ]] || return 1
  printf '%s' "$MASTER_PASSWORD" | jq -Rrs '"header = " + ("X-Desk-Token: " + . | @json)' > "$bootstrap_config" || return 1
  owner_password="$(openssl rand -hex 24)" || return 1
  [[ ${#owner_password} -ge 12 && ${#owner_password} -le 72 && "$owner_password" =~ ^[[:alnum:]-]+$ ]] || return 1
  printf '%s' "$owner_password" | jq -Rrs '"header = " + ("X-Desk-Token: " + . | @json)' > "$owner_config" || return 1
  printf '%s' "$owner_password" | jq -Rs '{password: ., password_confirmation: .}' > "$payload" || return 1
  unset owner_password

  log "asserting the bootstrap key is restricted to password setup"
  code="$(curl --config "$bootstrap_config" -s -m 10 -o "$body" -w '%{http_code}' "https://$PUBLIC_IP/api/status" 2>/dev/null)" || return 1
  [[ "$code" == "403" ]] && jq -e '.error == "set_password_required"' "$body" >/dev/null 2>&1 || return 1
  code="$(curl --config "$bootstrap_config" -s -m 10 -o "$body" -w '%{http_code}' "https://$PUBLIC_IP/api/onboarding" 2>/dev/null)" || return 1
  [[ "$code" == "200" ]] || return 1

  log "claiming a synthetic owner password on this disposable instance"
  code="$(curl --config "$bootstrap_config" -s -m 10 -o "$body" -w '%{http_code}' \
    -H 'Content-Type: application/json' --data-binary "@$payload" "https://$PUBLIC_IP/api/onboarding/master-password" 2>/dev/null)" || return 1
  [[ "$code" == "200" ]] || return 1
  log "asserting owner authentication returns HTTP 200 with health.mode=paper"
  code="$(curl --config "$owner_config" -s -m 10 -o "$body" -w '%{http_code}' "https://$PUBLIC_IP/api/status" 2>/dev/null)" || return 1
  [[ "$code" == "200" ]] && jq -e '.health.mode == "paper"' "$body" >/dev/null 2>&1 || return 1
  log "asserting the consumed bootstrap key is rejected"
  code="$(curl --config "$bootstrap_config" -s -m 10 -o "$body" -w '%{http_code}' "https://$PUBLIC_IP/api/onboarding" 2>/dev/null)" || return 1
  [[ "$code" == "401" || "$code" == "403" ]] || return 1
  log "OK: owner paper status and one-time bootstrap lifecycle verified"
}
if [[ -n "$MASTER_PASSWORD" ]]; then
  if ! verify_auth_lifecycle; then
    fail "trusted owner/bootstrap authentication lifecycle failed (response body withheld)"
  fi
  unset MASTER_PASSWORD
  if [[ -n "$AUTH_DIR" ]]; then
    rm -rf -- "$AUTH_DIR"
    AUTH_DIR=""
  fi
fi

# shoemoneyx-firewall.service runs after shoemoneyx-first-boot.service (After=), but its own
# ufw reset/reconfigure/enable sequence alone takes ~25s — MASTER_PASSWORD can already be
# readable before that finishes, so wait for it explicitly before asserting anything firewall-
# related, or the egress check below races the DOCKER-USER rules still being installed.
log "waiting for shoemoneyx-firewall.service to finish applying the DOCKER-USER policy"
FIREWALL_READY=0
for _ in $(seq 1 24); do
  if ssh_ "sudo systemctl is-active --quiet shoemoneyx-firewall.service" 2>/dev/null; then
    FIREWALL_READY=1
    break
  fi
  sleep 5
done
[[ "$FIREWALL_READY" -eq 1 ]] || fail "shoemoneyx-firewall.service never reached active state"

log "asserting the clock is synchronized to the Amazon Time Sync Service (JWT expiry and ACME need it)"
CLOCK_OK=0
for _ in $(seq 1 12); do
  SYNCED="$(ssh_ "timedatectl show -p NTPSynchronized --value" 2>/dev/null || true)"
  TRACKING="$(ssh_ "chronyc tracking" 2>/dev/null || true)"
  if [[ "$SYNCED" == "yes" && "$TRACKING" == *169.254.169.123* ]]; then
    CLOCK_OK=1
    break
  fi
  sleep 5
done
if [[ "$CLOCK_OK" -ne 1 ]]; then
  fail "clock not synchronized to 169.254.169.123 (NTPSynchronized='$SYNCED'); chronyc tracking: $TRACKING"
else
  log "OK: clock synchronized to the Amazon Time Sync Service"
fi

log "asserting docker compose ps shows web healthy and nginx up"
COMPOSE_PS_JSON="$(ssh_ "sudo docker compose -f /opt/shoemoneyx/docker-compose.yml ps --format json" 2>&1 || true)"
echo "$COMPOSE_PS_JSON" >&2
WEB_HEALTH="$(echo "$COMPOSE_PS_JSON" | jq -rs 'map(select(.Service=="web")) | .[0].Health // "missing"')"
NGINX_STATE="$(echo "$COMPOSE_PS_JSON" | jq -rs 'map(select(.Service=="nginx")) | .[0].State // "missing"')"
if [[ "$WEB_HEALTH" != "healthy" ]]; then
  fail "docker compose ps shows web health='$WEB_HEALTH' (want healthy)"
fi
if [[ "$NGINX_STATE" != "running" ]]; then
  fail "docker compose ps shows nginx state='$NGINX_STATE' (want running)"
fi

log "asserting the Docker egress policy holds from inside a container (443 reachable, plain :80 blocked)"
EGRESS_OUT="$(ssh_ "sudo docker compose -f /opt/shoemoneyx/docker-compose.yml exec -T web sh -c 'curl -s -m 5 -o /dev/null -w \"%{http_code}\" https://api.coinbase.com/ ; echo; curl -s -m 5 -o /dev/null -w \"%{http_code}\" http://example.com/ || echo blocked'" 2>&1 || true)"
echo "$EGRESS_OUT" >&2
HTTPS_CODE="$(echo "$EGRESS_OUT" | sed -n '1p')"
HTTP_RESULT="$(echo "$EGRESS_OUT" | sed -n '2p')"
if [[ ! "$HTTPS_CODE" =~ ^[2-4][0-9][0-9]$ ]]; then
  fail "container egress: https://api.coinbase.com/ returned '$HTTPS_CODE' (want a 2xx/3xx/4xx — reachable); egress policy may be blocking 443"
else
  log "OK: container reached https://api.coinbase.com/ ($HTTPS_CODE)"
fi
# curl still prints its write-out ("000") before the `|| echo blocked` lands on the same line.
if [[ "$HTTP_RESULT" != *blocked ]]; then
  fail "container egress: plain http://example.com/ was NOT blocked (got '$HTTP_RESULT'); DOCKER-USER egress policy is not holding"
else
  log "OK: plain http://example.com/ was blocked"
fi

log "asserting inbound 80 is closed again after the challenge"
if ! UFW_STATUS="$(ssh_ "sudo ufw status" 2>&1)"; then
  fail "could not read ufw status over ssh: $UFW_STATUS"
elif grep -Eq '^80(/tcp)?[[:space:]]' <<<"$UFW_STATUS"; then
  fail "ufw still has an allow rule for inbound 80"
else
  log "OK: ufw has no inbound 80 rule"
fi
if curl -s -o /dev/null -m 8 "http://$PUBLIC_IP/" 2>/dev/null; then
  fail "http://$PUBLIC_IP/ answered: something is listening on, and reachable at, port 80"
else
  log "OK: plain http on port 80 gets no answer"
fi

log "scanning ports 1-1024 with nmap"
NMAP_OUT="$(nmap -p 1-1024 -T4 "$PUBLIC_IP" 2>&1 || true)"
echo "$NMAP_OUT" >&2
OPEN_PORTS="$(echo "$NMAP_OUT" | awk '/\/tcp/ && /open/ {print $1}' | cut -d/ -f1 | sort -n | tr '\n' ' ')"
EXPECTED_PORTS="22 443 "
if [[ "$OPEN_PORTS" != "$EXPECTED_PORTS" ]]; then
  fail "unexpected open ports: got [$OPEN_PORTS] want [$EXPECTED_PORTS]"
else
  log "OK: only 22 and 443 open"
fi

if [[ "$FAILED" -ne 0 ]]; then
  echo "[test-boot] RESULT: FAIL" >&2
  exit 1
fi
echo "[test-boot] RESULT: PASS ($PUBLIC_IP, ami=$AMI_ID)" >&2
exit 0
