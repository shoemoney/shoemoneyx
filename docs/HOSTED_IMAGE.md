# Per-customer desk image (AWS AMI)

A reproducible, firewalled shoemoneyx desk that boots to paper mode with zero manual setup.
Built with [Packer](https://developer.hashicorp.com/packer) from `ops/image/desk.pkr.hcl`. Ubuntu
24.04 running Docker, pinned to a released `ghcr.io/shoemoney/shoemoneyx(-nginx)` image version —
the app is no longer built on the box, it runs the same published images every self-hoster runs;
everything secret is still generated on first boot.

## What's in the image

- Ubuntu 24.04 LTS, `t3.small`, Docker Engine + the compose plugin (from Docker's own apt repo)
- `/opt/shoemoneyx` cloned from `https://github.com/shoemoney/shoemoneyx` at the release tag
  `v<version>` — just the compose file, `docker/up.sh`, and `.env.example`; the app itself runs
  from the pinned `ghcr.io/shoemoney/shoemoneyx:<version>` / `shoemoneyx-nginx:<version>` images,
  pulled at build time (`docker compose pull`) so first boot needs no registry round trip
- systemd units: `shoemoneyx-first-boot` (runs once — gets the TLS certificate, pins the version,
  hands off to `docker/up.sh`, writes credentials), `shoemoneyx-firewall` (runs at every boot,
  after `docker.service`), and `shoemoneyx-acme.service` + `.timer` (certificate renewal, below)
- `certbot` >= 5.4 in `/opt/certbot` (from PyPI: Ubuntu 24.04's apt package predates IP-address
  certificates), with certbot and every dependency pinned in `ops/image/files/certbot-requirements.txt`
  (bump procedure in its header). Only the program: no certificate, ACME account key or email is in the image
- `ufw` (inbound 22 + 443 only, apart from the brief port-80 challenge window below, outbound 443 + 53 only, plus UDP 123 to the Amazon Time Sync address 169.254.169.123) **and** a `DOCKER-USER` iptables policy
  that mirrors the same outbound 443/53 rule for container-originated traffic — both owned by
  `ops/image/files/firewall.sh`, applied once at the end of provisioning and reapplied on every
  boot, because Docker rewrites iptables on every `dockerd` start and none of it survives a reboot

No secrets are baked in. `APP_KEY`, the 24-char `MASTER_PASSWORD`, and the local MariaDB password
are all generated on the box the first time it boots — by `docker/up.sh`, the same script a
self-hoster runs by hand, not by anything AMI-specific.

## Build

`ops/image/release.sh` is the one entry point a human runs to cut a desk-image release. It wraps
the packer build, the boot test, and the release bookkeeping that used to be three separate manual
steps.

```bash
bash ops/image/release.sh 0.2.0
```

`bash ops/image/release.sh --dry-run 0.2.0` prints every command the script would run and executes
nothing, so it works with no network and no AWS credentials. `bash ops/image/release.sh --status
0.2.0` prints that version's record from `ops/image/releases.json` and, if a Marketplace change
set has been submitted for it, the change set's current status. Set `RELEASE_NO_PUSH=1` to skip
the `git push origin main` the script runs after committing the release record.

Needs `packer` (`brew install hashicorp/tap/packer`, then `packer init ops/image/desk.pkr.hcl` once
per checkout) and an AWS identity with EC2 and AMI permissions (`aws sts get-caller-identity`
should already work), plus a default VPC in the target region (`us-east-1` unless overridden with
`-var region=...`). `version` has to match a real release: a `v<version>` git tag (for the compose
file / `up.sh` clone) and a published `<version>` tag on both ghcr.io images.

`release.sh` runs five stages in order.

1. Preflight checks everything at once and reports every failure rather than stopping at the
   first. `version` has to look like `X.Y.Z`, the `v<version>` tag has to exist on origin, the
   working tree has to be clean, and both `ghcr.io/shoemoney/shoemoneyx:<version>` and
   `ghcr.io/shoemoney/shoemoneyx-nginx:<version>` have to be pullable anonymously. That last check
   is what proves the `.github/workflows/image.yml` run triggered by `git push --tags` finished
   publishing both multi-arch manifests.
2. Build runs, under the hood, the same command as before:

   ```bash
   packer build -color=false -var version=0.2.0 ops/image/desk.pkr.hcl
   ```

   This step is convergent. It first asks EC2 for an AMI owned by this account tagged
   `Project=shoemoneyx` and `Version=<version>` in state `available`, and adopts that instead of
   building a second one. A rerun after a crash resumes instead of rebuilding. Builds in the
   default VPC on a temporary keypair/security group that Packer manages and tears down itself, and
   takes a few minutes: OS packages, Docker Engine, cloning the release tag, pulling the two pinned
   images, then the firewall script.
3. Boot test runs `ops/image/test-boot.sh` against the new AMI. See `## Boot test` below for what
   it checks.
4. Record upserts the release into `ops/image/releases.json`, commits it, pushes to origin unless
   `RELEASE_NO_PUSH=1` is set, and tags the AMI `BootTested=true`.
5. Marketplace is optional. If `ops/image/marketplace.env` exists (copy it from
   `ops/image/marketplace.env.example`, it's gitignored) or `MARKETPLACE_PRODUCT_ID` /
   `MARKETPLACE_ROLE_ARN` are set, this stage submits an `AddDeliveryOptions` change set for the new
   version via `aws marketplace-catalog start-change-set` and records the change set id and status.
   If it isn't configured, the script prints the seller-registration and product-creation steps and
   exits 0, with the release from stage 4 still recorded.

`ops/image/releases.json` replaces the old single-line AMI pointer file. It's a committed JSON
array with one object per released version, upserted in place as a release advances through
`built -> tested -> recorded -> published`. Each record looks like:

```json
{
  "version": "0.1.2",
  "commit": "<full 40-char git sha of the v0.1.2 tag's commit>",
  "ami_id": "ami-...",
  "ami_name": "shoemoneyx-desk-0.1.2-<packer-timestamp>",
  "built_at": "<ISO8601 UTC>",
  "boot_test": { "passed": true, "at": "<ISO8601 UTC>" },
  "marketplace": { "change_set_id": "...", "status": "...", "at": "<ISO8601 UTC>" }
}
```

`marketplace` is `null` until a change set has been submitted for that version.

## Boot test

`ops/image/test-boot.sh` is the source of truth for "does this image actually work." `release.sh`
runs it automatically as stage 3, and it launches a real `t3.small` from the AMI, in the default
VPC, behind a temporary security group (22 from the office IP only, 443 and 80 from anywhere; 80 is only there so Let's Encrypt can reach
the HTTP-01 challenge), and asserts:

1. HTTPS answers within 10 minutes; normal TLS verification of `/login` succeeds, the issuer is
   Let's Encrypt, the IP appears in the certificate SAN, and the AWS first-login guide appears
   without the certificate warning banner
2. only after those trust checks, retrieves the disposable instance's bootstrap credential over
   SSH; the bootstrap credential cannot access `/api/status` (HTTP 403
   with `set_password_required`), but can access `/api/onboarding`
3. claims a random owner password using matching password confirmation; the owner credential
   then gets HTTP 200 from `/api/status` with `health.mode=paper`, while the consumed bootstrap
   credential gets HTTP 401 or 403 from `/api/onboarding`. Authenticated curl requests verify TLS
   and keep credentials out of command arguments and failure logs
4. `docker compose ps` shows `web` healthy and `nginx` up
5. from inside the `web` container: an outbound HTTPS call succeeds and a plain outbound HTTP call
   is blocked — proving the `DOCKER-USER` egress policy holds for container traffic, not just the
   host's own `ufw` rules
6. `nmap -p 1-1024 <ip>` shows only `22` and `443` open, `ufw` has no inbound `80` rule, and a
   plain `http://<ip>/` gets no answer — the challenge window is shut again
7. the clock is synchronized (`timedatectl` reports `NTPSynchronized=yes` and `chronyc tracking`
   names `169.254.169.123`): Coinbase JWTs expire after 120 s and ACME validation needs a sane
   clock, and the firewall only lets NTP reach Amazon Time Sync, which chrony is pointed at

Then it terminates the instance and deletes the temporary security group, success or failure.

`release.sh` skips this stage when `ops/image/releases.json` already records a passing boot test
for the exact AMI id stage 2 just built or adopted. If the test fails, `release.sh` deregisters the
AMI, deletes its snapshots, records nothing, and exits non-zero, so a half-released image never
sits around waiting to be found by an unlucky customer.

`bash ops/image/test-acme.sh` is the local, no-AWS companion: it drives `files/acme.sh` against stubbed
`ufw`/`certbot`/`docker`/IMDS and asserts port 80 is open only while certbot runs and closed after
success, failure, SIGTERM and a stale leftover rule, that the certificate is seeded into the volume,
and that the self-signed fallback comes back when needed.

To run it by hand against a specific, newly built candidate AMI:

```bash
bash ops/image/test-boot.sh ami-xxxxxxxxxxxxxxxxx
```

Requires the `smx` EC2 key pair's private half at `~/.ssh/smx.pem` (override with
`DESK_TEST_SSH_KEY` / `DESK_TEST_KEY_NAME`), and `nmap`/`jq`/`aws` on the machine running the test.

### Live candidate acceptance

Local checks are a prerequisite, not proof of public CA issuance or the published image. Confirm
the candidate AMI was built from the reviewed fixes and record its source commit and both app and
nginx container digests. Consult `ops/image/releases.json` for recorded releases; an earlier
version's boot result cannot validate later source changes. With an authorized AWS identity,
the required EC2 permissions, and a **new candidate AMI ID**, run:

```bash
bash ops/image/test-acme.sh
python3 -B ops/image/test-test-boot.py
aws sts get-caller-identity
bash ops/image/test-boot.sh ami-xxxxxxxxxxxxxxxxx
```

The last command creates charged AWS resources, tests the candidate, and attempts to terminate
the instance and delete its temporary security group on exit. Check those resources are gone;
cleanup is best effort if AWS rejects a request. Use the default VPC, supported subnet, key pair,
and tools described above. This test proves its scripted boot checks on that candidate. A normal
browser visit needs a **separate retained disposable instance**, because `test-boot.sh` terminates
its instance. In a browser that trusts the public CA, visit the candidate's HTTPS login page
without a certificate bypass, confirm the AWS first-login guide and no trust warning, enter the
instance ID once, set a new password with confirmation, then confirm the old bootstrap key is
denied. Inspect the owner paper status, service health, synchronized clock, firewall, and the
closed port-80 challenge window.

For renewal acceptance, observe an actual later certificate replacement: record the initial
fingerprint and expiry, let a scheduled renewal run when the installed certificate is within
the source's renewal window, and confirm fingerprint and expiry advance while browser trust
persists. A `renew` call against a certificate that is not near expiry is a no-op and proves
nothing about issuance. Also stop and start a disposable instance without an Elastic IP, verify
its changed public IP has a newly trusted SAN and that the owner's password remains valid. Clean
up the retained instance and any test resources after collecting evidence. The local fixture's
self-signed certificate and synthetic trust marker do not replace this browser check. The ACME
script has no staging-CA mode; a staging certificate would not prove normal browser trust.

These live checks remain pending until evidence is collected for the exact candidate: public CA
issuance, normal browser trust and first-owner login, actual scheduled renewal, and trusted TLS
after a public IP change. Local harness results do not establish these outcomes. A repository
code promotion does not build or publish an AMI, update the release ledger, or establish
Marketplace acceptance.

`release.sh` is a release operation: its normal path builds or adopts an AMI, boot-tests it,
records and may push the release record, and can submit a Marketplace change set. Use only
`bash ops/image/release.sh --dry-run <version>` to inspect its planned sequence during validation.
Any later image release and Marketplace submission need their own authorization and acceptance
evidence.

## Boot flow

1. cloud-init brings up the box; `docker.service` starts.
2. `shoemoneyx-first-boot.service` runs `/opt/shoemoneyx-first-boot.sh` once (`ConditionPathExists=
   !/opt/shoemoneyx/.env` skips it on every later boot):
   - reads the version baked in at build time and reads the instance's `DOMAIN` tag over IMDS (no
     IAM role needed; launch with `--metadata-options InstanceMetadataTags=enabled` for this to
     work)
   - gets a browser-trusted certificate **before the stack starts** (see [Trusted TLS
     certificate](#trusted-tls-certificate)): `/opt/shoemoneyx-acme.sh renew`
   - runs `docker/up.sh` with `SHOEMONEYX_VERSION` pinned — this generates `.env` (APP_KEY, the
     24-char `MASTER_PASSWORD`, the local MariaDB password, `DESK_MODE=paper`,
     `EXCHANGE=coinbase`, `HUB_URL=https://hub.shoemoneyx.com`, `QUEUE_CONNECTION=redis`,
     `CACHE_STORE=redis`), brings up the compose stack (`migrate` runs first, then
     `web`/`nginx`/`queue`/`schedule`/`reverb`/`desk`/`feeder`/`mariadb`/`redis`), and blocks until
     `/api/status` answers
   - corrects `APP_URL` in `.env` from `up.sh`'s `https://localhost` default to the instance's
     real public IP or `DOMAIN`, and pins `SHOEMONEYX_VERSION=` in `.env` so a later manual
     `docker compose pull` on the box stays on this release by default; recreates the affected
     containers with `docker compose up -d`
   - writes `/root/shoemoneyx-credentials.txt` (`0600`, root-only)
3. `shoemoneyx-firewall.service` runs (`After=docker.service shoemoneyx-first-boot.service`),
   applying `ufw` and the `DOCKER-USER` egress policy. It also runs on every subsequent boot, since
   neither policy survives a reboot on its own.

## Trusted TLS certificate

The login page asks for the one-time bootstrap key, so the page must not make the buyer click
through a certificate warning first. Every instance therefore gets its own browser-trusted
certificate from [Let's Encrypt](https://letsencrypt.org/), at first boot, for its public IPv4
address (or for its `DOMAIN` tag, if set). Nothing certificate-related is in the AMI; the
ACME account and the key are created on the instance.

- **IP addresses** are supported by Let's Encrypt since
  [2026-01-15](https://letsencrypt.org/2026/01/15/6day-and-ip-general-availability), only on the
  `shortlived` [profile](https://letsencrypt.org/docs/profiles/) (160 hours, about 6.7 days), and
  only through the [HTTP-01 or TLS-ALPN-01](https://letsencrypt.org/docs/challenge-types/)
  challenges (DNS-01 cannot validate an IP). `certbot` 5.3+ has `--ip-address`, 5.4+ supports it
  with `--standalone`/`--webroot` ([EFF post](https://www.eff.org/deeplinks/2026/03/certbot-and-lets-encrypt-now-support-ip-address-certificates));
  certbot has no TLS-ALPN-01, so HTTP-01 on port 80 it is. The command, per instance:
  `certbot certonly --standalone --preferred-profile shortlived --ip-address <ip>
  --register-unsafely-without-email --agree-tos --non-interactive`.
- **The security group must allow inbound tcp/80** from the internet (the Marketplace
  recommended group does). The instance itself keeps port 80 shut except for the seconds a
  challenge runs: `/opt/shoemoneyx-acme.sh` opens `ufw` 80, runs certbot, and closes it in an
  `EXIT` trap that also fires on SIGTERM/SIGINT; both systemd units run
  `acme.sh close-port` as `ExecStopPost=`, which runs even after a SIGKILL or timeout; certbot is
  capped at 180 s; and `shoemoneyx-firewall.service` resets `ufw` on every boot.
- **Install:** nginx still reads `selfsigned.crt`/`selfsigned.key` in the compose `tls-data`
  volume (the paths `docker/nginx-tls-init.sh` uses and leaves alone), but those are now fixed
  symlinks into `current/`, itself a symlink to a generation directory. A new cert+key pair is
  staged in a fresh generation, checked (public keys must match), and made live by swapping the one
  `current` symlink, so nginx can never see a certificate with the wrong key. Then nginx is
  reloaded; if that fails, a `pending-reload` marker makes the next run retry it and the login
  status stays `fallback` until a reload has actually succeeded. Because this runs before `docker/up.sh`, nginx's very first
  start already serves it. The original self-signed pair stays in the volume as
  `fallback-selfsigned.*`.
- **Fallback:** the self-signed pair is generated (same command as `docker/nginx-tls-init.sh`)
  before the first trusted certificate replaces it, so there is always something to go back to.
  If issuance fails (no public IP, Let's Encrypt rate limit, no outbound 443, port 80 not
  reachable) the box serves the self-signed certificate and logs why. The first-boot
  journal shows it: `journalctl -u shoemoneyx-first-boot | grep -i cert`.
- **First-login trust gate:** `acme.sh` writes `trusted` or `fallback` (plus target and time) to a
  small status file in the `tls-status` volume, mounted read-only into the `web` container
  (`desk.tls_status_path`). Until a valid `trusted` marker matches the requested IP or hostname
  and the request uses HTTPS, the first-login page in AMI mode provides recovery guidance and
  no credential form. Browser login, existing bootstrap sessions, header tokens and direct
  password setup are all refused server-side. Wait and reload, or reboot from the EC2 console;
  do not enter the instance ID through a browser certificate warning. The marker is an
  activation signal, not a substitute for the browser validating the certificate. Once the
  owner has chosen a password, this first-login marker gate no longer applies. Non-AMI local
  setup does not depend on an AMI status file.
- **Retry:** `shoemoneyx-acme.service` has `Restart=on-failure` with `RestartSec=15min` and a
  start limit of 4 per hour, so a failed issuance is retried within 15 minutes while staying
  under Let's Encrypt's 5 failed validations per hour.
- **No wasted issuances:** if a previous run got a certificate but could not install it, the next
  run installs the lineage certbot already holds (when it covers the target and is not near expiry)
  instead of asking Let's Encrypt for another.
- **Renewal:** `shoemoneyx-acme.timer` runs 10 minutes after boot and then every 12 hours (plus up
  to 30 minutes of random delay); `shoemoneyx-acme.service` also runs once on every boot. A run
  re-issues when no certificate is installed, the public IP (or `DOMAIN`) changed, the installed
  certificate does not cover it, or it has less than 3 days left (21 for a `DOMAIN` certificate);
  otherwise it does nothing. After a stop/start without an Elastic IP the new address gets a new
  certificate on that boot. Attaching an Elastic IP to a *running* instance leaves the old-IP
  certificate in place for up to about 12.5 hours (next timer run) unless you reboot the instance. If a replacement cannot be issued and the installed certificate no
  longer fits, the self-signed one is put back.

## Troubleshooting

**The browser shows a certificate warning.** Do not enter the instance ID while your browser
shows a certificate warning: the login page says so too, and this is the page the one-time key
goes into. The instance could not get its automatic certificate yet. Wait a few minutes and
reload, or **reboot the instance from the EC2 console** (no SSH needed: the boot-time run retries
immediately). A failed issuance is also retried automatically every 15 minutes. If it persists:

1. Check the instance's security group allows inbound **TCP 80 from 0.0.0.0/0**. Let's Encrypt
   validates from outside, so without it issuance can never succeed. Port 80 stays closed on the
   instance itself except during the challenge.
2. Check the instance has a **public IPv4** (a public IP is required; IPv6-only or a private
   subnet cannot be validated).
3. Read the log: `sudo journalctl -u shoemoneyx-first-boot -u shoemoneyx-acme --no-pager | tail -50`
4. Retry now: `sudo /opt/shoemoneyx-acme.sh issue` (or `renew`, which does nothing while the
   current certificate is fine). Let's Encrypt allows only a handful of certificates per
   identifier per week, so don't loop it.
5. Reaching a stuck box: `ssh ubuntu@<ip>` with the key pair chosen at launch (port 22 must be
   allowed in the security group).

**Rate limits.** Repeated launches that recycle the same public IP can hit Let's Encrypt's
per-identifier limits; the box falls back to the self-signed certificate and the timer retries.

**Port 80 should be closed.** `sudo ufw status` must show no `80/tcp` line outside a running
renewal; `sudo /opt/shoemoneyx-acme.sh close-port` removes a leftover rule.

## Getting the credentials

No SSH needed for a normal launch: the first-login key is the **EC2 instance ID**
(`i-…`, shown in the AWS console). Open `https://<ip>/`, the login page says "First login uses your
instance ID once; you'll be asked to choose your own password", paste the ID, and you land on the
set-password screen (new password plus confirmation, 12+ characters). The ID opens nothing else,
neither in the browser nor as an `X-Desk-Token`, and stops working the moment you set your password.

For scripting, `test-boot.sh` retrieves the root-only credential file over SSH after verifying
TLS and the first-login page, then keeps disposable credentials in private files. Avoid printing
that file or passing credentials in command arguments or logs. It contains the URL, one-time
`MASTER_PASSWORD`, and local MariaDB password.

## Firewall: why DOCKER-USER exists

`ufw`'s "outbound 443+53 only" is a host-level policy enforced in the `OUTPUT` chain — it only
sees traffic the host itself originates. Container traffic is *forwarded* (host → bridge or bridge
→ host), which traverses `FORWARD`, and Docker installs its own `ACCEPT` rules there before `ufw`
ever gets a say. Without a separate policy, a container can reach anything outbound the moment it
starts, regardless of what `ufw` says.

`DOCKER-USER` is the chain Docker reserves specifically for operator rules and never overwrites.
`ops/image/files/firewall.sh` flushes and rebuilds it on every boot: allow established/related,
allow each compose bridge subnet out to tcp/443, tcp+udp/53, and the IMDS address on tcp/80 (all
scoped to `-o <default interface>` so bridge-to-bridge traffic — `web` → `mariadb`, `nginx` →
`web` — is never touched), then drop anything else a bridge subnet tries to send out that same
interface. Inbound to the published `443` still works: Docker's DNAT happens before `ufw`'s
`INPUT` chain and before `DOCKER-USER` sees the packet's real destination, which is expected.

## Update procedure

The image is immutable — there's no in-place app update path baked in. To ship a new release:

```bash
bash ops/image/release.sh 0.2.0
```

That runs preflight, builds or adopts the AMI, boot-tests it, and commits the result to
`ops/image/releases.json`, pushing it to origin unless `RELEASE_NO_PUSH=1` is set. See `## Build`
above for what each stage does, and run `bash ops/image/release.sh --status 0.2.0` afterward to
check on it, including any Marketplace change set.

An existing customer instance can also update itself in place, the same way any self-hoster does:

```bash
ssh -i ~/.ssh/smx.pem ubuntu@<ip>
cd /opt/shoemoneyx
sudo sed -i 's/^SHOEMONEYX_VERSION=.*/SHOEMONEYX_VERSION=0.2.0/' .env
sudo SHOEMONEYX_VERSION=0.2.0 docker compose pull
sudo SHOEMONEYX_VERSION=0.2.0 docker compose up -d
```

Nothing does this automatically — an instance stays on the version it was launched with, or the
version an operator manually bumps it to, until either happens. Re-launching a customer onto a
fresh AMI is a brand-new instance (new first-boot, new credentials, new `MASTER_PASSWORD`);
migrating their paper-trading history means restoring the `shoemoneyx` database from the old
instance before decommissioning it. There is no blue/green swap wired up yet — that's for the
billing/onboarding pipeline (TODO.md Phase 5), not this image.

## Memory, logs, health

- **Memory:** a 2 GB t3.small with no swap OOM-killed whichever process was largest. `provision.sh`
  now bakes a 2 GB `/swapfile` (swappiness 10), and `docker-compose.yml` gives every service a
  `mem_limit`/`memswap_limit`/`oom_score_adj` (mariadb `-900`, last to die; queue `+500`, first).
  MariaDB runs with `innodb_buffer_pool_size=160M` and `performance_schema=OFF`, php-fpm with
  `pm.max_children=4` and a 128M worker `memory_limit` (`docker/fpm-pool.conf`), the queue is one
  worker restarted past 256 MB, Redis is capped at 96 MB with `noeviction` (the cache locks `desk:cycle:*`/`desk:mutate:*` carry a TTL, so
  any evicting policy could drop one mid-order; the cache is a few small short-TTL keys and queue lists, far below the cap, and a full Redis fails writes loudly instead). Limits are `MEM_<SERVICE>` env vars in `.env` for bigger instance types.
- **Logs:** every service uses json-file with `max-size 10m` / `max-file 3`; Laravel logs to the
  `daily` channel, 7 days (`LOG_STACK=daily`, `LOG_DAILY_DAYS=7`). An instance launched from an older
  image keeps `LOG_STACK=single` in its `.env` until edited. `DeskEvent` rows are pruned after 30 days
  (`DESK_EVENT_RETENTION_DAYS`), failed jobs after 7 days, job batches after 48 h, by the scheduler.
- **Health:** `desk:run`, the scheduler and the queue worker each touch a heartbeat file under
  `storage/framework/` (the queue one via a job the scheduler dispatches every minute, so it proves
  a worker is actually pulling jobs). The compose healthchecks read those files, and the entrypoint
  watchdog (`supervise` in `docker/entrypoint.sh`) kills a wedged process so `restart: unless-stopped`
  revives it. No autoheal sidecar: it needs `/var/run/docker.sock`, which is root on the host. Queue
  staleness is 70 min because a backtest may hold the worker for its 3600 s job timeout.
  For alerting, add a CloudWatch alarm on `StatusCheckFailed` for the instance; container health is
  visible with `docker compose ps`.

## Backups and snapshots

Two layers, because they cover different failures:

1. **Nightly dump (in the stack).** The `backup` service writes
   `shoemoneyx-<UTC timestamp>.sql.gz` to the `backups` volume at 03:15 UTC (and once at start if the
   newest is over 24 h old), keeping the newest `BACKUP_KEEP` (default 7). It uses
   `--single-transaction`, so it does not lock the desk. The dump holds positions, intents and the
   encrypted exchange keys, so treat it like the DB. It guards against a bad migration or a dropped
   table; it lives on the same EBS volume, so it does **not** survive losing the volume.
   Restore (MariaDB's root password is random, so load as the app user):
   ```bash
   cd /opt/shoemoneyx && docker compose stop web desk queue schedule reverb feeder backup \
     && docker compose run --rm --no-deps -T \
       --entrypoint sh backup -c 'gzip -t /backups/shoemoneyx-XXXX.sql.gz && zcat /backups/shoemoneyx-XXXX.sql.gz | MYSQL_PWD="$DB_PASSWORD" mariadb -h mariadb -u shoemoneyx shoemoneyx' \
     && docker compose up -d \
     || echo "RESTORE FAILED: services left stopped, fix the dump or the error above, do not start the stack"
   ```
   The password never appears on a host command line: the container already has `DB_PASSWORD` from
   compose, and the dumps themselves are written `0600` (umask 077) by the unprivileged backup user.
2. **EBS snapshots (off the instance).** Tag the **instance** (the policy below targets `INSTANCE`, so
   tagging only its volumes would match nothing) and let Data Lifecycle Manager snapshot its attached
   volumes daily, keeping 7:
   ```bash
   aws ec2 create-tags --resources <instance-id> --tags Key=smx-backup,Value=daily
   aws dlm create-lifecycle-policy --description "smx daily" --state ENABLED \
     --execution-role-arn arn:aws:iam::<acct>:role/AWSDataLifecycleManagerDefaultRole \
     --policy-details '{"PolicyType":"EBS_SNAPSHOT_MANAGEMENT","ResourceTypes":["INSTANCE"],
       "TargetTags":[{"Key":"smx-backup","Value":"daily"}],
       "Schedules":[{"Name":"daily","CreateRule":{"Interval":24,"IntervalUnit":"HOURS","Times":["04:00"]},
         "RetainRule":{"Count":7},"CopyTags":true}]}'
   ```
   Restore by creating a volume from the snapshot and swapping it in, or launching an instance from a
   snapshot-derived AMI. Snapshots are crash-consistent; MariaDB recovers from its redo log on start.

## Cost

`t3.small` in `us-east-1`, on-demand: **~$15/month** (730 hrs × $0.0208/hr ≈ $15.18), plus a 20 GB
gp3 root volume (~$1.60/month) and negligible data transfer for a single paper-trading desk. Call
it **~$17/month per customer instance** before any Reserved/Savings Plan discount.
