#!/usr/bin/env bash
# ops/image/release.sh <version> | --dry-run <version> | --status <version>
#
# Drives one shoemoneyx desk AMI release through built -> tested -> recorded -> published,
# persisting state in ops/image/releases.json. See docs/HOSTED_IMAGE.md for the image itself.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

RELEASES_JSON="ops/image/releases.json"
MARKETPLACE_ENV="ops/image/marketplace.env"
DEFAULT_MARKETPLACE_ROLE_ARN="arn:aws:iam::911482840889:role/shoemoneyx-marketplace-ami-ingestion"
# Same office IP ops/image/test-boot.sh opens SSH from for its temporary security group -- keep
# the two in sync.
OFFICE_CIDR="68.185.216.69/32"
REGION="${AWS_REGION:-us-east-1}"

DRY_RUN=0
STATUS_MODE=0
VERSION=""

# capture()'s stand-in value when DRY_RUN is set. Callers never parse this string -- anywhere a
# specific fabricated value is needed to keep the printed sequence going (an AMI id, a change-set
# id, ...), they branch on DRY_RUN explicitly instead of reading it back out of this placeholder.
CAPTURE_PLACEHOLDER="DRY-RUN-PLACEHOLDER"
# Obviously-fake AMI id (real ones are lowercase hex only) so the dry-run sequence has something
# concrete to thread through the boot-test/record/marketplace steps that follow a build.
DRY_RUN_AMI_ID="ami-dryrun00000000"

TMPFILES=()

mktemp_tracked() {
  local t
  t="$(mktemp)"
  TMPFILES+=("$t")
  printf '%s' "$t"
}

cleanup() {
  local status=$?
  local f
  # ${a[@]+"${a[@]}"} because an empty array expansion is an unbound-variable error under
  # set -u in bash 3.2, which is what /bin/bash still is on macOS.
  for f in ${TMPFILES[@]+"${TMPFILES[@]}"}; do
    [[ -n "$f" ]] && rm -f "$f"
  done
  exit "$status"
}
trap cleanup EXIT

log() {
  printf '[release] %s\n' "$*" >&2
}

die() {
  log "$*"
  exit 1
}

# Shell-quote-and-join argv for printing. printf %q is unreadable (backslash-escapes everything);
# this only quotes an argument when it actually needs it.
fmt_cmd() {
  local out="" sep="" arg
  for arg in "$@"; do
    if [[ -n "$arg" && "$arg" =~ ^[A-Za-z0-9_@%+=:,./-]+$ ]]; then
      out+="${sep}${arg}"
    else
      out+="${sep}'$(printf '%s' "$arg" | sed "s/'/'\\\\''/g")'"
    fi
    sep=" "
  done
  printf '%s' "$out"
}

run() {
  if [[ "$DRY_RUN" -eq 1 ]]; then
    printf '  %s\n' "$(fmt_cmd "$@")"
    return 0
  fi
  "$@"
}

capture() {
  if [[ "$DRY_RUN" -eq 1 ]]; then
    # Printed to stderr, not stdout, so `x=$(capture ...)` still gets a clean value -- only the
    # placeholder below lands on stdout.
    printf '  %s\n' "$(fmt_cmd "$@")" >&2
    printf '%s' "$CAPTURE_PLACEHOLDER"
    return 0
  fi
  "$@"
}

release_json() {
  local version="$1"
  [[ -f "$RELEASES_JSON" ]] || return 0
  jq -c --arg v "$version" '.[] | select(.version == $v)' "$RELEASES_JSON"
}

upsert_release() {
  local version="$1" patch="$2"

  if [[ "$DRY_RUN" -eq 1 ]]; then
    log "dry-run: would upsert ${RELEASES_JSON} for ${version} with ${patch}"
    return 0
  fi

  [[ -f "$RELEASES_JSON" ]] || printf '[]' > "$RELEASES_JSON"

  local tmp
  tmp="$(mktemp_tracked)"
  jq --arg v "$version" --argjson patch "$patch" '
    if any(.[]; .version == $v)
    then map(if .version == $v then . * $patch else . end)
    else . + [({
      version: $v, commit: null, ami_id: null, ami_name: null,
      built_at: null, boot_test: null, marketplace: null
    } * $patch)]
    end
  ' "$RELEASES_JSON" > "$tmp"
  mv "$tmp" "$RELEASES_JSON"
}

release_state() {
  local version="$1" json
  json="$(release_json "$version")"
  if [[ -z "$json" ]]; then
    printf 'unknown'
    return 0
  fi
  local published recorded tested built
  published="$(printf '%s' "$json" | jq -r '(.marketplace.change_set_id // "") | length > 0')"
  recorded="$(printf '%s' "$json" | jq -r '(.built_at // null) != null')"
  tested="$(printf '%s' "$json" | jq -r '(.boot_test.passed // false) == true')"
  built="$(printf '%s' "$json" | jq -r '(.ami_id // null) != null')"
  if [[ "$published" == "true" ]]; then
    printf 'published'
  elif [[ "$recorded" == "true" ]]; then
    printf 'recorded'
  elif [[ "$tested" == "true" ]]; then
    printf 'tested'
  elif [[ "$built" == "true" ]]; then
    printf 'built'
  else
    printf 'unknown'
  fi
}

# Commits releases.json and pushes, but only when there's actually something new to commit -- a
# converged rerun must be a no-op here or `git commit` would trip set -e on "nothing to commit".
# In dry-run, upsert_release never touches the file, so the real git-status guard would always say
# "unchanged"; force the maximal path so the commit/push sequence still prints.
commit_if_changed() {
  local message="$1" changed

  if [[ "$DRY_RUN" -eq 1 ]]; then
    changed=1
  elif [[ -n "$(git status --porcelain "$RELEASES_JSON")" ]]; then
    changed=1
  else
    changed=0
  fi

  if [[ "$changed" -eq 0 ]]; then
    log "${RELEASES_JSON} unchanged; nothing to commit"
    return 0
  fi

  run git add "$RELEASES_JSON"
  run git commit -m "$message"
  if [[ "${RELEASE_NO_PUSH:-}" == "1" ]]; then
    log "push skipped because RELEASE_NO_PUSH is set"
  else
    run git push origin main
  fi
}

preflight() {
  local version="$1"
  local failures=()

  if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    failures+=("version '${version}' does not match MAJOR.MINOR.PATCH")
  fi

  if [[ "$DRY_RUN" -eq 1 ]]; then
    run git ls-remote --tags origin
  elif ! git ls-remote --tags origin | grep -q "refs/tags/v${version}\$"; then
    failures+=("tag v${version} not found on origin")
  fi

  if [[ "$DRY_RUN" -eq 1 ]]; then
    run git status --porcelain
  else
    local porcelain
    porcelain="$(git status --porcelain)"
    if [[ -n "$porcelain" ]]; then
      failures+=("working tree is not clean (git status --porcelain has output)")
    fi
  fi

  local repo
  for repo in "shoemoney/shoemoneyx" "shoemoney/shoemoneyx-nginx"; do
    if [[ "$DRY_RUN" -eq 1 ]]; then
      run curl -sS "https://ghcr.io/token?scope=repository:${repo}:pull"
      # ghcr rejects the dotted "index.v1.json" spelling with a 404 MANIFEST_UNKNOWN; the
      # plus-sign OCI media type is the one that actually returns 200.
      run curl -sS -o /dev/null -w '%{http_code}' \
        -H "Authorization: Bearer <token>" \
        -H "Accept: application/vnd.oci.image.index.v1+json" \
        "https://ghcr.io/v2/${repo}/manifests/${version}"
      continue
    fi

    local token
    token="$(curl -sS "https://ghcr.io/token?scope=repository:${repo}:pull" 2>/dev/null | jq -r '.token // empty' || true)"
    if [[ -z "$token" ]]; then
      failures+=("ghcr image ${repo}:${version} not pullable (could not obtain a pull token)")
      continue
    fi

    local code
    code="$(curl -sS -o /dev/null -w '%{http_code}' \
      -H "Authorization: Bearer ${token}" \
      -H "Accept: application/vnd.oci.image.index.v1+json" \
      "https://ghcr.io/v2/${repo}/manifests/${version}" 2>/dev/null || printf '000')"
    if [[ "$code" != "200" ]]; then
      failures+=("ghcr image ${repo}:${version} not pullable (HTTP ${code})")
    fi
  done

  if ((${#failures[@]} > 0)); then
    local f
    for f in "${failures[@]}"; do
      log "preflight: ${f}"
    done
    exit 1
  fi
}

BUILD_AMI_ID=""

step_build() {
  local version="$1"
  local existing_ami

  existing_ami="$(capture aws ec2 describe-images --owners self \
    --filters "Name=tag:Project,Values=shoemoneyx" "Name=tag:Version,Values=${version}" "Name=state,Values=available" \
    --query 'Images[0].ImageId' --output text --region "$REGION")"

  # Dry-run always takes the maximal path: pretend nothing is built yet so build, boot test, and
  # record all get printed, per the dry-run contract above.
  if [[ "$DRY_RUN" -eq 1 ]]; then
    existing_ami="None"
  fi

  if [[ -n "$existing_ami" && "$existing_ami" != "None" ]]; then
    log "AMI already built for version ${version}: ${existing_ami}"
    BUILD_AMI_ID="$existing_ami"
    return 0
  fi

  if [[ "$DRY_RUN" -eq 1 ]]; then
    run packer build -color=false -var "version=${version}" ops/image/desk.pkr.hcl
    BUILD_AMI_ID="$DRY_RUN_AMI_ID"
    return 0
  fi

  local packer_log ami_id
  packer_log="$(mktemp_tracked)"
  packer build -color=false -var "version=${version}" ops/image/desk.pkr.hcl 2>&1 | tee "$packer_log"

  ami_id="$(grep -oE "${REGION}: ami-[a-f0-9]+" "$packer_log" | tail -n1 | grep -oE 'ami-[a-f0-9]+' || true)"
  if [[ -z "$ami_id" ]]; then
    die "packer build produced no AMI id in its output (see ${packer_log})"
  fi
  BUILD_AMI_ID="$ami_id"
}

BOOT_TEST_FROM_CACHE=0

step_boot_test() {
  local version="$1" ami_id="$2"
  BOOT_TEST_FROM_CACHE=0
  local already_passed="false"

  if [[ "$DRY_RUN" -ne 1 ]]; then
    local json
    json="$(release_json "$version")"
    if [[ -n "$json" ]]; then
      already_passed="$(printf '%s' "$json" | jq -r --arg ami "$ami_id" '(.ami_id == $ami) and ((.boot_test.passed // false) == true)')"
    fi
  fi

  if [[ "$already_passed" == "true" ]]; then
    log "boot test already recorded as passed for ${ami_id}"
    BOOT_TEST_FROM_CACHE=1
    return 0
  fi

  local test_boot_bin
  if command -v test-boot.sh >/dev/null 2>&1; then
    # Lets a stub on PATH stand in for the real, AWS-touching test-boot.sh during testing.
    test_boot_bin="$(command -v test-boot.sh)"
  else
    test_boot_bin="ops/image/test-boot.sh"
  fi

  if run bash "$test_boot_bin" "$ami_id"; then
    return 0
  fi

  log "boot test failed for ${ami_id}; rolling back"
  local snapshot_ids
  # Must run before deregister-image: once the AMI is gone, its BlockDeviceMappings go with it.
  snapshot_ids="$(capture aws ec2 describe-images --image-ids "$ami_id" \
    --query 'Images[0].BlockDeviceMappings[].Ebs.SnapshotId' --output text --region "$REGION")"
  run aws ec2 deregister-image --image-id "$ami_id" --region "$REGION"

  local snap_arr snap
  read -ra snap_arr <<< "$snapshot_ids"
  for snap in ${snap_arr[@]+"${snap_arr[@]}"}; do
    [[ -n "$snap" && "$snap" != "None" ]] || continue
    run aws ec2 delete-snapshot --snapshot-id "$snap" --region "$REGION"
  done
  exit 1
}

step_record() {
  local version="$1" ami_id="$2"
  local existing_json existing_built_at existing_boot_test
  existing_json="$(release_json "$version")"
  existing_built_at=""
  existing_boot_test=""
  if [[ -n "$existing_json" ]]; then
    existing_built_at="$(printf '%s' "$existing_json" | jq -r '.built_at // empty')"
    existing_boot_test="$(printf '%s' "$existing_json" | jq -c '.boot_test // empty')"
  fi

  local commit
  commit="$(git rev-list -n 1 "v${version}" 2>/dev/null || true)"
  if [[ -z "$commit" ]]; then
    if [[ "$DRY_RUN" -eq 1 ]]; then
      # A dry-run for a version not yet tagged locally still has to print the whole sequence.
      commit="0000000000000000000000000000000000000000"
    else
      die "no v${version} tag in this checkout; run git fetch --tags first"
    fi
  fi

  local ami_name_raw ami_name_json
  ami_name_raw="$(capture aws ec2 describe-images --image-ids "$ami_id" --query 'Images[0].Name' --output text --region "$REGION")"
  if [[ "$DRY_RUN" -eq 1 ]]; then
    ami_name_json="null"
  elif [[ -z "$ami_name_raw" || "$ami_name_raw" == "None" ]]; then
    if [[ -n "$existing_json" ]]; then
      ami_name_json="$(printf '%s' "$existing_json" | jq -c '.ami_name')"
    else
      ami_name_json="null"
    fi
  else
    ami_name_json="$(printf '%s' "$ami_name_raw" | jq -Rs .)"
  fi

  local built_at_json
  if [[ -n "$existing_built_at" ]]; then
    built_at_json="$(printf '%s' "$existing_built_at" | jq -Rs .)"
  else
    # date's own trailing newline would otherwise survive into the JSON string: `jq -Rs` slurps
    # stdin verbatim, and $() is what actually strips it.
    local now_utc
    now_utc="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    built_at_json="$(printf '%s' "$now_utc" | jq -Rs .)"
  fi

  local boot_test_json
  if [[ "$BOOT_TEST_FROM_CACHE" -eq 1 && -n "$existing_boot_test" ]]; then
    boot_test_json="$existing_boot_test"
  else
    boot_test_json="$(jq -nc --arg at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" '{passed: true, at: $at}')"
  fi

  local patch
  patch="$(jq -nc \
    --arg commit "$commit" \
    --arg ami_id "$ami_id" \
    --argjson ami_name "$ami_name_json" \
    --argjson built_at "$built_at_json" \
    --argjson boot_test "$boot_test_json" \
    '{commit: $commit, ami_id: $ami_id, ami_name: $ami_name, built_at: $built_at, boot_test: $boot_test}')"
  upsert_release "$version" "$patch"

  commit_if_changed "chore: 🏷️ desk AMI ${version} = ${ami_id} (boot-tested)"

  run aws ec2 create-tags --resources "$ami_id" --tags Key=BootTested,Value=true --region "$REGION"
}

step_marketplace() {
  local version="$1" ami_id="$2"

  if [[ -f "$MARKETPLACE_ENV" ]]; then
    # Operator-supplied and gitignored -- not part of this repo's tracked config.
    # shellcheck source=/dev/null
    source "$MARKETPLACE_ENV"
  fi
  local role_arn="${MARKETPLACE_ROLE_ARN:-$DEFAULT_MARKETPLACE_ROLE_ARN}"
  local product_id="${MARKETPLACE_PRODUCT_ID:-}"

  if [[ -z "$product_id" ]]; then
    printf 'The release for %s is recorded. Only the Marketplace listing step is pending:\n' "$version"
    printf '  - register as an AWS Marketplace seller\n'
    printf '  - create the AMI product in the AWS Marketplace Management Portal\n'
    printf '  - copy ops/image/marketplace.env.example to ops/image/marketplace.env and set MARKETPLACE_PRODUCT_ID= to the product id the portal issues\n'
    exit 0
  fi

  local existing_json existing_cs
  existing_json="$(release_json "$version")"
  existing_cs=""
  if [[ -n "$existing_json" ]]; then
    existing_cs="$(printf '%s' "$existing_json" | jq -r '.marketplace.change_set_id // empty')"
  fi

  if [[ -n "$existing_cs" ]]; then
    local describe_json cs_status patch
    describe_json="$(capture aws marketplace-catalog describe-change-set --catalog AWSMarketplace --change-set-id "$existing_cs" --region "$REGION")"
    if [[ "$DRY_RUN" -eq 1 ]]; then
      cs_status="SUBMITTED"
    else
      cs_status="$(printf '%s' "$describe_json" | jq -r '.Status')"
    fi
    patch="$(jq -nc --arg cs "$existing_cs" --arg status "$cs_status" --arg at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
      '{marketplace: {change_set_id: $cs, status: $status, at: $at}}')"
    upsert_release "$version" "$patch"
    return 0
  fi

  local release_notes
  release_notes="$(git tag -l --format='%(contents)' "v${version}")"
  [[ -n "$release_notes" ]] || release_notes="$version"
  local notes_json
  notes_json="$(printf '%s' "$release_notes" | jq -Rs .)"

  local usage_text
  usage_text="No SSH is needed. Open https://<instance-ip>/ in a browser. The login page asks for a password: paste the EC2 instance ID (i-...) shown in the AWS console as the bootstrap password. Onboarding then makes you choose your own password before anything else."
  local usage_json
  usage_json="$(printf '%s' "$usage_text" | jq -Rs .)"

  local body_file
  body_file="$(mktemp_tracked)"
  # Port 22 is scoped to the same office IP constant ops/image/test-boot.sh uses for its own
  # temporary security group.
  cat > "$body_file" <<EOF
{
  "Catalog": "AWSMarketplace",
  "ChangeSet": [
    {
      "ChangeType": "AddDeliveryOptions",
      "Entity": { "Type": "AmiProduct@1.0", "Identifier": "${product_id}" },
      "DetailsDocument": {
        "Version": { "VersionTitle": "${version}", "ReleaseNotes": ${notes_json} },
        "DeliveryOptions": [
          {
            "Details": {
              "AmiDeliveryOptionDetails": {
                "AmiSource": {
                  "AmiId": "${ami_id}",
                  "AccessRoleArn": "${role_arn}",
                  "UserName": "ubuntu",
                  "OperatingSystemName": "UBUNTU",
                  "OperatingSystemVersion": "24.04"
                },
                "UsageInstructions": ${usage_json},
                "RecommendedInstanceType": "t3.small",
                "SecurityGroups": [
                  { "IpProtocol": "tcp", "FromPort": 443, "ToPort": 443, "IpRanges": ["0.0.0.0/0"] },
                  { "IpProtocol": "tcp", "FromPort": 22, "ToPort": 22, "IpRanges": ["${OFFICE_CIDR}"] }
                ]
              }
            }
          }
        ]
      }
    }
  ],
  "Intent": "APPLY"
}
EOF

  local response change_set_id status patch
  response="$(capture aws marketplace-catalog start-change-set --catalog AWSMarketplace --cli-input-json "file://${body_file}" --region "$REGION")"
  if [[ "$DRY_RUN" -eq 1 ]]; then
    change_set_id="cs-dryrun00000000"
    status="SUBMITTED"
  else
    change_set_id="$(printf '%s' "$response" | jq -r '.ChangeSetId')"
    status="$(printf '%s' "$response" | jq -r '.Status // "SUBMITTED"')"
  fi

  patch="$(jq -nc --arg cs "$change_set_id" --arg status "$status" --arg at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    '{marketplace: {change_set_id: $cs, status: $status, at: $at}}')"
  upsert_release "$version" "$patch"

  commit_if_changed "chore: 📦 submit Marketplace change set for ${version}"
}

cmd_status() {
  local version="$1" json
  json="$(release_json "$version")"
  if [[ -z "$json" ]]; then
    log "no release recorded for version ${version}"
    return 0
  fi

  printf '%s' "$json" | jq .
  log "state: $(release_state "$version")"

  local cs
  cs="$(printf '%s' "$json" | jq -r '.marketplace.change_set_id // empty')"
  if [[ -n "$cs" ]]; then
    local describe_json cs_status
    describe_json="$(aws marketplace-catalog describe-change-set --catalog AWSMarketplace --change-set-id "$cs" --region "$REGION")"
    cs_status="$(printf '%s' "$describe_json" | jq -r '.Status')"
    log "marketplace change set ${cs}: ${cs_status}"
  fi
}

usage() {
  cat >&2 <<'EOF'
usage: release.sh [--dry-run] <version>
       release.sh --status <version>

version must look like MAJOR.MINOR.PATCH, e.g. 0.1.2
EOF
}

# Dry-run rule: local reads (releases.json, marketplace.env, -f tests, git status/rev-list, which
# never touch the network) execute for real. Every command that touches the network, AWS, packer,
# or test-boot, or that mutates git, goes through run()/capture() and is only printed. The two
# convergence skip-checks (already-built AMI, already-passed boot test) are forced to "not found"
# so the maximal path -- build, boot test, record -- is always the one printed.
while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run)
      DRY_RUN=1
      ;;
    --status)
      STATUS_MODE=1
      ;;
    -*)
      usage
      exit 2
      ;;
    *)
      if [[ -n "$VERSION" ]]; then
        usage
        exit 2
      fi
      VERSION="$1"
      ;;
  esac
  shift
done

if [[ -z "$VERSION" ]]; then
  usage
  exit 2
fi
if [[ "$DRY_RUN" -eq 1 && "$STATUS_MODE" -eq 1 ]]; then
  usage
  exit 2
fi

if [[ "$STATUS_MODE" -eq 1 ]]; then
  cmd_status "$VERSION"
  exit 0
fi

preflight "$VERSION"
step_build "$VERSION"
step_boot_test "$VERSION" "$BUILD_AMI_ID"
step_record "$VERSION" "$BUILD_AMI_ID"
step_marketplace "$VERSION" "$BUILD_AMI_ID"
