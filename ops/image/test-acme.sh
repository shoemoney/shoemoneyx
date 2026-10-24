#!/usr/bin/env bash
# ops/image/test-acme.sh — local, no-AWS test of ops/image/files/acme.sh with stubbed ufw, certbot,
# docker, curl (IMDS), flock and timeout (openssl is real). Proves what must hold without a real boot:
#   - inbound 80 is open only while certbot runs and is shut after success, failure, SIGTERM, and
#     when a stale rule is found
#   - the cert+key pair is published as ONE atomic swap and a mismatched or half-failed publish never
#     disturbs the active pair
#   - a failed nginx reload keeps the status at "fallback" and is retried on the next run
#   - interrupted layout conversion reuses known staging links without replacing unrelated state
#   - an issued-but-uninstalled lineage is reused instead of burning another issuance
#   - IMDS without a public IP skips issuance; the self-signed fallback comes back when needed
#   bash ops/image/test-acme.sh
set -euo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/files/acme.sh"
T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT
mkdir -p "$T/bin" "$T/app" "$T/le/shoemoneyx-desk"
FAILS=0
ok() { echo "ok   - $*"; }
bad() { echo "FAIL - $*"; FAILS=$((FAILS + 1)); }
check() { local name="$1"; shift; if "$@"; then ok "$name"; else bad "$name"; fi; }

# A real cert+key with an IP SAN, used by the certbot stub (which signs nothing, it just copies).
mkpair() { # mkpair <outprefix> <ip>
  openssl req -x509 -nodes -newkey rsa:2048 -days 6 -keyout "$1.key" -out "$1.crt" -subj "/CN=x" \
    -addext "subjectAltName=IP:$2" >/dev/null 2>&1
}
if ! mkpair "$T/probe" 203.0.113.7 || ! openssl x509 -in "$T/probe.crt" -noout -checkip 203.0.113.7 2>/dev/null | grep -q 'does match'; then
  echo "skip - this openssl lacks -addext/-checkip (the Ubuntu image has them)"
  exit 0
fi
mkpair "$T/other" 203.0.113.7   # same IP, different key: for the mismatched-pair case

cat > "$T/bin/ufw" <<'EOF'
#!/usr/bin/env bash
case "$*" in
  "allow in 80/tcp") touch "$T/port80"; echo "open" >> "$T/events" ;;
  "--force delete allow in 80/tcp") rm -f "$T/port80"; echo "close" >> "$T/events" ;;
  status) [[ -e "$T/port80" ]] && echo "80/tcp                     ALLOW       Anywhere" || echo "Status: active" ;;
esac
exit 0
EOF
cat > "$T/bin/certbot" <<'EOF'
#!/usr/bin/env bash
[[ "$1" == delete ]] && { echo "certbot-delete" >> "$T/events"; exit 0; }
[[ -e "$T/port80" ]] && echo "certbot-saw-80-open" >> "$T/events"
echo "certbot-issue" >> "$T/events"
echo "$*" > "$T/certbot-args"
[[ "${FAKE_SLEEP:-0}" != 0 ]] && sleep "$FAKE_SLEEP"
[[ "${FAKE_CERTBOT:-ok}" == fail ]] && exit 1
ip="$(sed -n 's/.*--ip-address \([0-9.]*\).*/\1/p' "$T/certbot-args")"
openssl req -x509 -nodes -newkey rsa:2048 -days 6 -keyout "$T/le/shoemoneyx-desk/privkey.pem" \
  -out "$T/le/shoemoneyx-desk/fullchain.pem" -subj "/CN=x" -addext "subjectAltName=IP:$ip" >/dev/null 2>&1
if [[ "${FAKE_CERTBOT:-ok}" == mismatch ]]; then cp "$T/other.key" "$T/le/shoemoneyx-desk/privkey.pem"; fi
EOF
cat > "$T/bin/docker" <<'EOF'
#!/usr/bin/env bash
dir_for() { case "$1" in *tls-status) echo "$T/status" ;; *) echo "$T/vol" ;; esac; }
case "$1 $2" in
  "volume inspect") d="$(dir_for "$3")"; [[ -d "$d" ]] && echo "$d" || exit 1 ;;
  "volume create") [[ -n "${FAKE_NO_VOLUME:-}" ]] && exit 0; mkdir -p "$(dir_for "${@: -1}")"; echo "volume-created" >> "$T/events" ;;
  "compose -p") [[ -n "${FAKE_NGINX:-}" ]] && echo cid123 ;;
  "exec cid123")
    echo "nginx-reload" >> "$T/events"
    [[ -n "${FAKE_RELOAD_FAIL:-}" ]] && exit 1
    cert_pub="$(openssl x509 -in "$T/vol/selfsigned.crt" -noout -pubkey)" || exit 1
    key_pub="$(openssl pkey -in "$T/vol/selfsigned.key" -pubout)" || exit 1
    [[ -n "$cert_pub" && "$cert_pub" == "$key_pub" ]] || exit 1
    echo "nginx-reload-valid-pair" >> "$T/events"
    ;;
esac
exit 0
EOF
cat > "$T/bin/curl" <<'EOF'
#!/usr/bin/env bash
case "$*" in
  *"-X PUT"*) echo tok ;;
  *public-ipv4*) [[ -n "${FAKE_IP:-}" ]] && echo "$FAKE_IP" || exit 22 ;;
  *) exit 22 ;;
esac
EOF
# Fails the one rename that would publish a new pair, to prove a failed swap leaves the old pair intact.
cat > "$T/bin/mv" <<'EOF'
#!/usr/bin/env bash
if [[ -n "${FAKE_MV_FAIL:-}" && "$*" == *".current.new"* ]]; then exit 1; fi
exec /bin/mv "$@"
EOF
printf '#!/usr/bin/env bash\nexit 0\n' > "$T/bin/flock"
printf '#!/usr/bin/env bash\nshift\nexec "$@"\n' > "$T/bin/timeout"
chmod +x "$T/bin/"*

run() { # run [VAR=value ...] : runs acme.sh $MODE with the stubs on PATH
  env PATH="$T/bin:$PATH" T="$T" APP_DIR="$T/app" STATE_DIR="$T/state" LE_LIVE_ROOT="$T/le" LOCK_FILE="$T/lock" \
    FAKE_IP="${FAKE_IP-203.0.113.7}" "$@" bash "$SCRIPT" "$MODE"
}
reset() {
  rm -rf "$T/vol" "$T/status" "$T/state" "$T/events" "$T/port80" "$T/certbot-args"
  rm -f "$T/le/shoemoneyx-desk/"*.pem
  : > "$T/events"
}
events() { grep -c "^$1\$" "$T/events" || true; }
sum() { shasum "$1" | cut -d' ' -f1; }
active_pair_ok() { # the pair nginx would load is consistent and non-empty
  [[ -s "$T/vol/selfsigned.crt" && -s "$T/vol/selfsigned.key" ]] \
    && [[ "$(openssl x509 -in "$T/vol/selfsigned.crt" -noout -pubkey)" == "$(openssl pkey -in "$T/vol/selfsigned.key" -pubout)" ]]
}

echo "--- success, volume seeded before the stack exists"
reset; MODE=renew
rc=0; run env >/dev/null 2>&1 || rc=$?
check "exit 0" test "$rc" -eq 0
check "certbot ran with port 80 open" grep -q certbot-saw-80-open "$T/events"
check "port 80 closed afterwards" test ! -e "$T/port80"
check "cert and key are fixed symlinks into current/" test -L "$T/vol/selfsigned.crt" -a -L "$T/vol/selfsigned.key" -a -L "$T/vol/current"
check "the active pair matches" active_pair_ok
check "active cert covers the IP" bash -c "openssl x509 -in '$T/vol/selfsigned.crt' -noout -checkip 203.0.113.7 | grep -q 'does match'"
check "key is 0600" test "$(python3 -c 'import os, stat, sys; print(format(stat.S_IMODE(os.stat(sys.argv[1]).st_mode), "o"))' "$T/vol/current/tls.key")" = 600
check "ip identifier + shortlived profile" grep -q -- '--preferred-profile shortlived --ip-address 203.0.113.7' "$T/certbot-args"
check "no email, anonymous account" grep -q -- '--register-unsafely-without-email' "$T/certbot-args"
check "state records the target" grep -q '^ip:203.0.113.7$' "$T/state/target"
check "a self-signed fallback exists" grep -q 'BEGIN CERTIFICATE' "$T/vol/fallback-selfsigned.crt"
check "status says trusted" grep -q '^trusted ip:203.0.113.7 ' "$T/status/status"
check "no pending reload left" test ! -e "$T/state/pending-reload"
check "only one generation directory remains" test "$(find "$T/vol" -maxdepth 1 -name 'gen-*' | wc -l | tr -d ' ')" -eq 1

for ext in crt key; do
  echo "--- retry after a kill leaves the .$ext staging link"
  reset; MODE=renew
  mkdir -p "$T/vol/gen-interrupted"
  cp "$T/probe.crt" "$T/vol/gen-interrupted/tls.crt"
  cp "$T/probe.key" "$T/vol/gen-interrupted/tls.key"
  ln -s gen-interrupted "$T/vol/current"
  cp "$T/probe.key" "$T/vol/selfsigned.key"
  if [[ "$ext" == key ]]; then
    # The cert rename already happened when the key staging link was left behind.
    ln -s current/tls.crt "$T/vol/selfsigned.crt"
  else
    cp "$T/probe.crt" "$T/vol/selfsigned.crt"
  fi
  ln -s "current/tls.$ext" "$T/vol/.link.$ext"
  rc=0; run FAKE_NGINX=1 > "$T/retry-output" 2>&1 || rc=$?
  check "$ext residue: retry exits 0" test "$rc" -eq 0
  check "$ext residue: installed pair matches" active_pair_ok
  check "$ext residue: nginx reloaded a valid pair" grep -q '^nginx-reload-valid-pair$' "$T/events"
  check "$ext residue: status says trusted" grep -q '^trusted ip:203.0.113.7 ' "$T/status/status"
  check "$ext residue: staging link consumed" test ! -L "$T/vol/.link.$ext"
  check "$ext residue: no staging-link creation error" bash -c '! grep -q "File exists" "$1"' _ "$T/retry-output"
  : > "$T/events"
  rc=0; run FAKE_NGINX=1 >/dev/null 2>&1 || rc=$?
  check "$ext residue: subsequent renew exits 0 without issuance" test "$rc:$(events certbot-issue)" = 0:0
done

echo "--- a fresh, matching cert is left alone"
: > "$T/events"
rc=0; run env >/dev/null 2>&1 || rc=$?
check "exit 0" test "$rc" -eq 0
check "no issuance, port 80 never opened" test "$(events certbot-issue)$(events open)" = "00"

for residue in file symlink; do
  echo "--- unrelated $residue at a staging path is preserved"
  reset; MODE=renew
  mkdir -p "$T/vol"
  cp "$T/probe.crt" "$T/vol/selfsigned.crt"
  cp "$T/probe.key" "$T/vol/selfsigned.key"
  if [[ "$residue" == file ]]; then
    echo unrelated > "$T/vol/.link.crt"
  else
    ln -s unrelated "$T/vol/.link.crt"
  fi
  rc=0; run FAKE_NGINX=1 >/dev/null 2>&1 || rc=$?
  check "$residue collision: non-zero exit" test "$rc" -ne 0
  if [[ "$residue" == file ]]; then
    check "$residue collision: original contents preserved" grep -q '^unrelated$' "$T/vol/.link.crt"
  else
    check "$residue collision: original link preserved" test "$(readlink "$T/vol/.link.crt")" = unrelated
  fi
  check "$residue collision: active pair matches" active_pair_ok
  check "$residue collision: nginx not reloaded" test "$(events nginx-reload)" = 0
done

echo "--- certbot fails"
reset; MODE=renew
rc=0; run FAKE_CERTBOT=fail >/dev/null 2>&1 || rc=$?
check "non-zero exit" test "$rc" -ne 0
check "port 80 closed after failure" test ! -e "$T/port80"
check "last firewall event is a close" test "$(grep -E '^(open|close)$' "$T/events" | tail -n1)" = close
check "status says fallback" grep -q '^fallback ' "$T/status/status"

echo "--- install failure when the volume cannot be resolved"
reset; MODE=renew
rc=0; run FAKE_NO_VOLUME=1 >/dev/null 2>&1 || rc=$?
check "non-zero exit" test "$rc" -ne 0
check "target not recorded as installed" test ! -e "$T/state/target"
check "port 80 closed" test ! -e "$T/port80"

echo "--- SIGTERM while certbot is running"
reset; MODE=renew
( env PATH="$T/bin:$PATH" T="$T" APP_DIR="$T/app" STATE_DIR="$T/state" LE_LIVE_ROOT="$T/le" LOCK_FILE="$T/lock" \
  FAKE_IP=203.0.113.7 FAKE_SLEEP=3 bash "$SCRIPT" renew >/dev/null 2>&1 ) &
pid=$!
for _ in $(seq 1 30); do [[ -e "$T/port80" ]] && break; sleep 0.1; done
check "port 80 was open mid-challenge" test -e "$T/port80"
kill -TERM "$(pgrep -P "$pid" -f acme.sh | head -n1 || echo "$pid")" 2>/dev/null || kill -TERM "$pid" 2>/dev/null || true
wait "$pid" 2>/dev/null || true
check "port 80 closed after SIGTERM" test ! -e "$T/port80"

echo "--- stale open rule from a killed run is closed"
reset; MODE=close-port; touch "$T/port80"
run env >/dev/null 2>&1
check "close-port removes the stale rule" test ! -e "$T/port80"

echo "--- no public IP"
reset; MODE=renew
rc=0; run FAKE_IP="" >/dev/null 2>&1 || rc=$?
check "exit 0 (self-signed fallback)" test "$rc" -eq 0
check "certbot never invoked" test ! -e "$T/certbot-args"
check "port 80 never opened" test "$(events open)" = 0

echo "--- IP change: old lineage dropped, new cert issued and published"
reset; MODE=renew
run env >/dev/null 2>&1
old_sum="$(sum "$T/vol/selfsigned.crt")"
: > "$T/events"
run FAKE_IP=203.0.113.99 >/dev/null 2>&1 || true
check "re-issues for the new IP" grep -q -- '--ip-address 203.0.113.99' "$T/certbot-args"
check "old lineage deleted" grep -q certbot-delete "$T/events"
check "new target recorded" grep -q '^ip:203.0.113.99$' "$T/state/target"
check "active cert changed and is consistent" bash -c "[[ '$old_sum' != \"\$(shasum '$T/vol/selfsigned.crt' | cut -d' ' -f1)\" ]]"
check "active pair matches" active_pair_ok

echo "--- failure on a changed IP restores the self-signed fallback"
reset; MODE=renew
run env >/dev/null 2>&1
run FAKE_IP=203.0.113.99 FAKE_CERTBOT=fail >/dev/null 2>&1 || true
check "active cert is the fallback" test "$(sum "$T/vol/selfsigned.crt")" = "$(sum "$T/vol/fallback-selfsigned.crt")"
check "active pair matches" active_pair_ok
check "state cleared so the next run retries" test ! -e "$T/state/target"
check "status says fallback" grep -q '^fallback ' "$T/status/status"

echo "--- certbot hands back a cert whose key does not match"
reset; MODE=renew
run env >/dev/null 2>&1
rc=0; run FAKE_IP=203.0.113.99 FAKE_CERTBOT=mismatch >/dev/null 2>&1 || rc=$?
check "non-zero exit" test "$rc" -ne 0
check "the mismatched key was never published" test "$(sum "$T/vol/selfsigned.key")" != "$(sum "$T/other.key")"
check "old-IP pair replaced by the self-signed fallback, not by the bad pair" test "$(sum "$T/vol/selfsigned.crt")" = "$(sum "$T/vol/fallback-selfsigned.crt")"
check "active pair matches" active_pair_ok

echo "--- the swap itself fails (between staging and publishing)"
reset; MODE=renew
run env >/dev/null 2>&1
before_crt="$(sum "$T/vol/selfsigned.crt")"; before_key="$(sum "$T/vol/selfsigned.key")"
rc=0; run FAKE_IP=203.0.113.99 FAKE_MV_FAIL=1 >/dev/null 2>&1 || rc=$?
check "non-zero exit" test "$rc" -ne 0
check "cert and key both unchanged" test "$(sum "$T/vol/selfsigned.crt")$(sum "$T/vol/selfsigned.key")" = "$before_crt$before_key"
check "active pair matches" active_pair_ok
check "no stray generation or staging link left" test "$(find "$T/vol" -maxdepth 1 \( -name 'gen-*' -o -name '.current.new' \) | wc -l | tr -d ' ')" -eq 1

echo "--- next run reuses the already-issued lineage instead of issuing again"
: > "$T/events"
rc=0; run FAKE_IP=203.0.113.99 >/dev/null 2>&1 || rc=$?
check "exit 0" test "$rc" -eq 0
check "no new issuance" test "$(events certbot-issue)" = 0
check "no lineage delete" test "$(events certbot-delete)" = 0
check "installed cert covers the new IP" bash -c "openssl x509 -in '$T/vol/selfsigned.crt' -noout -checkip 203.0.113.99 | grep -q 'does match'"

echo "--- nginx reload fails: not trusted yet, retried next run"
reset; MODE=renew
rc=0; run FAKE_NGINX=1 FAKE_RELOAD_FAIL=1 >/dev/null 2>&1 || rc=$?
check "non-zero exit" test "$rc" -ne 0
check "status is not trusted" grep -q '^fallback ' "$T/status/status"
check "pending marker kept" test -e "$T/state/pending-reload"
: > "$T/events"
rc=0; run FAKE_NGINX=1 >/dev/null 2>&1 || rc=$?
check "next run exits 0" test "$rc" -eq 0
check "reload retried without a new issuance" test "$(events nginx-reload)$(events certbot-issue)" = "10"
check "status now trusted" grep -q '^trusted ' "$T/status/status"
check "pending marker cleared" test ! -e "$T/state/pending-reload"

echo "--- IP change on a volume whose pair nginx generated itself still ends on a working pair"
reset; MODE=renew
mkdir -p "$T/vol" "$T/state"
cp "$T/probe.crt" "$T/vol/selfsigned.crt"; cp "$T/probe.key" "$T/vol/selfsigned.key"
echo "ip:198.51.100.9" > "$T/state/target"
run FAKE_CERTBOT=fail >/dev/null 2>&1 || true
check "fallback generated on demand and active" test "$(sum "$T/vol/selfsigned.crt")" = "$(sum "$T/vol/fallback-selfsigned.crt")"
check "active pair matches" active_pair_ok

if [[ "$FAILS" -ne 0 ]]; then
  echo "RESULT: $FAILS failure(s)"
  exit 1
fi
echo "RESULT: all passed"
