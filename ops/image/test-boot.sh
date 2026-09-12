#!/usr/bin/env bash
# ops/image/test-boot.sh <ami-id>
#
# Boots one t3.small from the given AMI in the default VPC behind a temporary
# security group (22 from the office IP only, 443 from anywhere), then proves
# the desk image works the way a customer's box must:
#   1. HTTPS comes up within 10 minutes
#   2. GET /api/status returns 200 with "mode":"paper"
#   3. nmap of the low 1024 ports shows nothing open except 22 and 443
# Terminates the instance and deletes the security group on the way out,
# success or failure. Exits non-zero on any assertion failure.
set -euo pipefail

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

INSTANCE_ID=""
SG_ID=""
FAILED=0

log() { echo "[test-boot] $*" >&2; }

cleanup() {
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
  "IpProtocol=tcp,FromPort=443,ToPort=443,IpRanges=[{CidrIp=0.0.0.0/0,Description='https'}]" >/dev/null

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

MASTER_PASSWORD=""
if [[ "$UP" -eq 1 ]]; then
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

if [[ -n "$MASTER_PASSWORD" ]]; then
  log "asserting GET /api/status is 200 with paper mode"
  BODY_FILE="$(mktemp)"
  HTTP_CODE="$(curl -k -s -m 10 -o "$BODY_FILE" -w '%{http_code}' \
    -H "X-Desk-Token: $MASTER_PASSWORD" "https://$PUBLIC_IP/api/status" || echo "000")"
  BODY="$(cat "$BODY_FILE")"
  rm -f "$BODY_FILE"
  if [[ "$HTTP_CODE" != "200" ]]; then
    fail "GET /api/status returned HTTP $HTTP_CODE (expected 200); body: $BODY"
  elif [[ "$BODY" != *'"mode":"paper"'* ]]; then
    fail "GET /api/status body did not contain \"mode\":\"paper\"; body: $BODY"
  else
    log "OK: /api/status is 200 and paper mode"
  fi
fi

ssh_() {
  ssh -i "$SSH_KEY" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=5 "ubuntu@$PUBLIC_IP" "$@"
}

log "asserting docker compose ps shows web healthy and nginx up"
COMPOSE_PS="$(ssh_ "sudo docker compose -f /opt/shoemoneyx/docker-compose.yml ps" 2>&1 || true)"
echo "$COMPOSE_PS" >&2
if ! echo "$COMPOSE_PS" | grep -qE '^web\b.*\(healthy\)'; then
  fail "docker compose ps does not show web as healthy"
fi
if ! echo "$COMPOSE_PS" | grep -qE '^nginx\b.*Up'; then
  fail "docker compose ps does not show nginx as Up"
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
if [[ "$HTTP_RESULT" != "blocked" ]]; then
  fail "container egress: plain http://example.com/ was NOT blocked (got '$HTTP_RESULT'); DOCKER-USER egress policy is not holding"
else
  log "OK: plain http://example.com/ was blocked"
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
