# Per-customer desk image (AWS AMI)

A reproducible, firewalled shoemoneyx desk that boots to paper mode with zero manual setup.
Built with [Packer](https://developer.hashicorp.com/packer) from `ops/image/desk.pkr.hcl`.
Ubuntu 24.04, PHP 8.4, Node LTS, Redis, local MariaDB, nginx — the app is cloned from `main`
and built (composer + npm) into the image itself; everything secret is generated on first boot.

## What's in the image

- Ubuntu 24.04 LTS, `t3.small`
- PHP 8.4-fpm + extensions (mysql, redis, bcmath, sodium, intl, zip, mbstring, opcache w/ JIT)
- Node LTS (build-time only — `node_modules` is removed after `npm run build`)
- Redis + MariaDB, both bound to loopback only
- nginx serving `public/` over HTTPS with a build-time self-signed cert
- The desk cloned from `https://github.com/shoemoney/shoemoneyx` (`main`), `composer install
  --no-dev` and `npm run build` already done
- systemd units: `shoemoneyx-desk` (`desk:run`), `shoemoneyx-queue` (`queue:work`),
  `shoemoneyx-schedule` (`schedule:work` — daily report, backtest loop, strategy sync, contest
  reports), `shoemoneyx-reverb` (`reverb:start`), and `shoemoneyx-first-boot` (runs once, gates
  the other four with `Requires=`)
- `ufw`: inbound 22 + 443 only, outbound 443 + 53 only, enabled as the last provisioning step so
  the build's own apt/git/npm/composer fetches aren't blocked by the firewall it bakes in

No secrets are baked in. `APP_KEY`, the 24-char `MASTER_PASSWORD`, and the local MariaDB password
are all generated on the box the first time it boots.

## Build

```bash
brew install hashicorp/tap/packer   # if not already installed
cd shoemoneyx                       # repo root — the template's file paths are relative to here
packer init ops/image/desk.pkr.hcl
packer validate ops/image/desk.pkr.hcl
packer build ops/image/desk.pkr.hcl
```

Needs an AWS identity with EC2 + AMI permissions (`aws sts get-caller-identity` should already
work) and a default VPC in the target region (`us-east-1` unless overridden with `-var
region=...`). Builds in the default VPC on a temporary keypair/security group that Packer manages
and tears down itself. Takes roughly 10-12 minutes: OS packages, the `ondrej/php` PPA, Node,
cloning + building the app, then `ufw enable`.

On success, Packer prints the new AMI id. Write it to `ops/image/latest-ami.txt` (committed, so
the last known-good AMI is always in git):

```bash
echo ami-xxxxxxxxxxxxxxxxx > ops/image/latest-ami.txt
```

## Boot test

`ops/image/test-boot.sh` is the source of truth for "does this image actually work." It launches
a real `t3.small` from the AMI, in the default VPC, behind a temporary security group (22 from
the office IP only, 443 from anywhere), and asserts:

1. HTTPS answers within 10 minutes
2. `GET /api/status` (with the `X-Desk-Token` read off the instance via
   `ssh ubuntu@<ip> sudo cat /root/shoemoneyx-credentials.txt`) returns `200` with `"mode":"paper"`
3. `nmap -p 1-1024 <ip>` shows only `22` and `443` open

Then it terminates the instance and deletes the temporary security group, success or failure.

```bash
bash ops/image/test-boot.sh "$(cat ops/image/latest-ami.txt)"
```

Requires the `smx` EC2 key pair's private half at `~/.ssh/smx.pem` (override with
`DESK_TEST_SSH_KEY` / `DESK_TEST_KEY_NAME`), and `nmap`/`jq`/`aws` on the machine running the test.

## Boot flow

1. cloud-init brings up the box; systemd starts `nginx`, `php8.4-fpm`, `mariadb`, `redis-server`.
2. `shoemoneyx-first-boot.service` runs `/opt/shoemoneyx-first-boot.sh` once:
   - generates `APP_KEY`, a 24-char `MASTER_PASSWORD`, and a random local DB password
   - creates the `shoemoneyx` MariaDB database + user (loopback only)
   - writes `/opt/shoemoneyx/.env` from `.env.example` with `DESK_MODE=paper`,
     `EXCHANGE=coinbase`, `HUB_URL=https://hub.shoemoneyx.com`, the generated secrets, and
     `QUEUE_CONNECTION=redis` / `CACHE_STORE=redis`
   - runs `migrate --force` and `market:sync-products`
   - if the instance has a `DOMAIN` tag (read over IMDS — no IAM role needed; launch with
     `--metadata-options InstanceMetadataTags=enabled` for this to work), requests a real
     cert via `certbot --nginx`. Certbot's HTTP-01 challenge needs port 80 reachable, which the
     image's own firewall/security-group don't open by default — open it temporarily (SG only;
     `ufw` doesn't block loopback-adjacent traffic on 80 either way once you add the SG rule) if
     you want a browser-trusted cert. No `DOMAIN` tag means it stays on the build-time
     self-signed cert, which is the safe default.
   - writes `/root/shoemoneyx-credentials.txt` (`0600`, root-only)
3. `shoemoneyx-desk`, `shoemoneyx-queue`, `shoemoneyx-schedule`, `shoemoneyx-reverb` start
   (`Requires=shoemoneyx-first-boot.service`), and the dashboard is live at `https://<ip>/`.

## Getting the credentials

```bash
ssh -i ~/.ssh/smx.pem ubuntu@<ip> sudo cat /root/shoemoneyx-credentials.txt
```

Prints the URL, the `MASTER_PASSWORD` (send this as the `X-Desk-Token` header or `?token=` query
param, or log in at `/login`), and the local MariaDB password.

## Update procedure

The image is immutable — there's no in-place app update path baked in. To ship a new build:

```bash
packer build ops/image/desk.pkr.hcl          # rebuilds from current main, produces a new AMI
bash ops/image/test-boot.sh <new-ami-id>     # proves it before anyone gets it
echo <new-ami-id> > ops/image/latest-ami.txt
git add ops/image/latest-ami.txt && git commit -m "chore: new desk image"
```

Existing customer instances keep running the AMI they were launched from — nothing auto-updates
them. Re-launching a customer onto the new AMI is a fresh instance (new first-boot, new
credentials, new `MASTER_PASSWORD`); migrating their paper-trading history means restoring the
`shoemoneyx` database from the old instance before decommissioning it. There is no blue/green
swap wired up yet — that's for the billing/onboarding pipeline (TODO.md Phase 5), not this image.

## Cost

`t3.small` in `us-east-1`, on-demand: **~$15/month** (730 hrs × $0.0208/hr ≈ $15.18), plus a 20 GB
gp3 root volume (~$1.60/month) and negligible data transfer for a single paper-trading desk. Call
it **~$17/month per customer instance** before any Reserved/Savings Plan discount.
