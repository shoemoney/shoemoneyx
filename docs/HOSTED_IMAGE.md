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
- systemd units: `shoemoneyx-first-boot` (runs once — pins the version, hands off to
  `docker/up.sh`, writes credentials) and `shoemoneyx-firewall` (runs at every boot, after
  `docker.service`)
- `ufw` (inbound 22 + 443 only, outbound 443 + 53 only) **and** a `DOCKER-USER` iptables policy
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
VPC, behind a temporary security group (22 from the office IP only, 443 from anywhere), and
asserts:

1. HTTPS answers within 10 minutes
2. `GET /api/status` (with the `X-Desk-Token` read off the instance via
   `ssh ubuntu@<ip> sudo cat /root/shoemoneyx-credentials.txt`) returns `200` with `"mode":"paper"`
3. `docker compose ps` shows `web` healthy and `nginx` up
4. from inside the `web` container: an outbound HTTPS call succeeds and a plain outbound HTTP call
   is blocked — proving the `DOCKER-USER` egress policy holds for container traffic, not just the
   host's own `ufw` rules
5. `nmap -p 1-1024 <ip>` shows only `22` and `443` open

Then it terminates the instance and deletes the temporary security group, success or failure.

`release.sh` skips this stage when `ops/image/releases.json` already records a passing boot test
for the exact AMI id stage 2 just built or adopted. If the test fails, `release.sh` deregisters the
AMI, deletes its snapshots, records nothing, and exits non-zero, so a half-released image never
sits around waiting to be found by an unlucky customer.

To run it by hand against a specific AMI:

```bash
bash ops/image/test-boot.sh ami-xxxxxxxxxxxxxxxxx
```

Requires the `smx` EC2 key pair's private half at `~/.ssh/smx.pem` (override with
`DESK_TEST_SSH_KEY` / `DESK_TEST_KEY_NAME`), and `nmap`/`jq`/`aws` on the machine running the test.

## Boot flow

1. cloud-init brings up the box; `docker.service` starts.
2. `shoemoneyx-first-boot.service` runs `/opt/shoemoneyx-first-boot.sh` once (`ConditionPathExists=
   !/opt/shoemoneyx/.env` skips it on every later boot):
   - reads the version baked in at build time and reads the instance's `DOMAIN` tag over IMDS (no
     IAM role needed; launch with `--metadata-options InstanceMetadataTags=enabled` for this to
     work)
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
   - if a `DOMAIN` tag is present, requests a real cert via `certbot certonly --standalone`
     (briefly opens `ufw`'s inbound 80, needs the security group to allow it too — same
     requirement as before), and on success writes a `docker-compose.override.yml` that bind-mounts
     the live cert/key over the nginx image's self-signed pair and recreates the `nginx` service.
     No `DOMAIN` tag, or a failed certbot run, means it stays on the image's build-time self-signed
     cert — the safe default either way.
   - writes `/root/shoemoneyx-credentials.txt` (`0600`, root-only)
3. `shoemoneyx-firewall.service` runs (`After=docker.service shoemoneyx-first-boot.service`),
   applying `ufw` and the `DOCKER-USER` egress policy. It also runs on every subsequent boot, since
   neither policy survives a reboot on its own.

## Getting the credentials

No SSH needed for a normal launch: the bootstrap `MASTER_PASSWORD` is the **EC2 instance ID**
(`i-…`, shown in the AWS console). Open `https://<ip>/`, the login page says so, paste the ID, and
onboarding makes you choose your own password before anything else. The desk refuses an empty
password on this image because it is reachable from the internet.

For scripting (this is what `test-boot.sh` does):

```bash
ssh -i ~/.ssh/smx.pem ubuntu@<ip> sudo cat /root/shoemoneyx-credentials.txt
```

Prints the URL, the `MASTER_PASSWORD` (send this as the `X-Desk-Token` header or `?token=` query
param, or log in at `/login`), and the local MariaDB password.

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

## Cost

`t3.small` in `us-east-1`, on-demand: **~$15/month** (730 hrs × $0.0208/hr ≈ $15.18), plus a 20 GB
gp3 root volume (~$1.60/month) and negligible data transfer for a single paper-trading desk. Call
it **~$17/month per customer instance** before any Reserved/Savings Plan discount.
