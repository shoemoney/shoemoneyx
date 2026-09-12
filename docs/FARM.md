# Sweep farm — running backtests across every box you own

One image (`Dockerfile`), three roles (`ROLE=worker|feeder|desk`). The **desk host** (today: the MBP;
later an Ultra or an AWS box with an Elastic IP) runs MariaDB + Redis + feeder + desk:run and holds the
Coinbase key. Every other machine is a **worker**: it only needs to reach the desk host's Redis (6379)
and MariaDB (3306) on the LAN.

## Desk host (once)
    # MariaDB: let LAN workers in (read candles, write backtests)
    mysql -uroot -e "CREATE USER IF NOT EXISTS 'shoemoneyx'@'<lan-subnet>' IDENTIFIED BY 'shoemoneyx'; GRANT ALL ON shoemoneyx.* TO 'shoemoneyx'@'<lan-subnet>';"
    # /opt/homebrew/etc/my.cnf → bind-address = 0.0.0.0 ; redis.conf → bind 0.0.0.0 + requirepass if you like
    brew services restart mariadb redis

## Worker node — native (Macs / Z890 with PHP 8.4 installed)
    git clone git@github.com:shoemoney/shoemoneyx.git && cd shoemoneyx && composer install --no-dev
    cp .env.example .env   # set APP_KEY (copy from desk), REDIS_HOST=<desk ip>, DB_HOST=<desk ip>, REDIS_PREFIX=shoemoneyx-, QUEUE_CONNECTION=redis, CACHE_STORE=redis
    bin/desk worker          # one worker per core

## Worker node — docker (Pis, or anything)
    docker run -d --restart unless-stopped --name shoemoneyx-worker \
      -e ROLE=worker -e WORKERS=4 -e QUEUES=backtests-light -e REDIS_CLIENT=phpredis -e APP_KEY='<desk APP_KEY>' \
      -e REDIS_HOST=<desk ip> -e DB_HOST=<desk ip> -e DB_USERNAME=shoemoneyx -e DB_PASSWORD=shoemoneyx \
      -e REDIS_PREFIX=shoemoneyx- -e CACHE_STORE=redis -e QUEUE_CONNECTION=redis \
      shoemoneyx:local
    # or: WORKERS=4 REDIS_HOST=… DB_HOST=… docker compose up -d worker
    # Pi swarm: docker service create --replicas <N pis> … same env, or one compose per Pi.

## Build / publish the image (from the MBP with Docker Desktop)
    # Build arm64 locally and ship to Pis via tarball (current working path)
    docker buildx build --platform linux/arm64 -t shoemoneyx:local --load .
    docker save shoemoneyx:local | gzip > ~/tmp/shoemoneyx-image.tar.gz
    # For each Pi (user@<worker-host>):
    scp -i ~/.ssh/pi ~/tmp/shoemoneyx-image.tar.gz user@<worker-host>:/tmp/shoemoneyx-image.tar.gz
    ssh -i ~/.ssh/pi user@<worker-host> docker load -i /tmp/shoemoneyx-image.tar.gz
    # Restart container (preserves env vars):
    envs=$(docker inspect shoemoneyx-worker --format '{{range .Config.Env}}-e {{printf "%q" .}} {{end}}')
    docker rm shoemoneyx-worker
    eval docker run -d --restart unless-stopped --name shoemoneyx-worker $envs shoemoneyx:local

    # (BLOCKED: ghcr.io push currently fails — token lacks write:packages; amd64 under QEMU fails)
    # bin/desk image --push     # publish ghcr.io/shoemoney/shoemoneyx:latest (docker login ghcr.io first)

### amd64 build under QEMU: two gotchas (both worked around, one needs a retry sometimes)

**1. apt-sandbox (deterministic, fixed).** `apt-get update` under `--platform linux/amd64` emulation
fails with "At least one invalid signature was encountered" — not a real signature problem, but QEMU
user-mode breaking the seteuid apt does to drop to the `_apt` user for sandboxed downloads. Fix:
`apt-get -o APT::Sandbox::User=root update` (or the persistent
`echo 'APT::Sandbox::User "root";' > /etc/apt/apt.conf.d/99qemu`), applied in the Dockerfile's `base`
stage — signature verification stays on and works fine once apt isn't sandboxed. This is in the
Dockerfile and apt now succeeds cleanly on every build.

**2. Intermittent QEMU cc1/ld crash during the PHP extension compile (flaky, not deterministic —
retry the build if it happens).** Past the apt fix, `docker-php-ext-install pdo_mysql bcmath sodium
intl zip pcntl opcache` occasionally has a child `cc`/`cc1`/`ld` process SIGSEGV under QEMU's x86_64
user-mode emulation. Five builds in a row hit it, each crashing at a different, unpredictable point —
`cc1: internal compiler error: Segmentation fault` compiling `intl/msgformat/msgformat_format.c`
under `-j$(nproc)`; a bare 2-object `cc -shared … -o .libs/pcntl.so` link segfaulting under `-j1`
(ruling out compile complexity); segfaults compiling `intl/spoofchecker/spoofchecker_create.c` and
`intl/formatter/formatter_main.c`; and once autoconf's own compiler sanity check for `pdo_mysql`
silently failed 19s in, before the real compile even started. That spread (19s–237s in, across
compiles, a trivial link, and autoconf's probe, under both `-j1` and `-j$(nproc)`) is QEMU itself
occasionally corrupting an emulated child process, not a bug in any one extension or Dockerfile flag
— dropping `-j` to 1 is still worth keeping (it was the standard first fix to try) but does not
eliminate the flakiness. **The 6th build in a row succeeded outright with no changes**, so this is a
retry-and-it-usually-works gotcha, not a hard blocker: if `docker buildx build --platform linux/amd64`
dies with a "Segmentation fault" or a mid-compile `configure: error: cannot compute suffix of object
files`, just run it again. If it fails several times in a row, `docker builder prune` / restart colima
to rule out host memory pressure (the colima VM was seen at 8GiB with the host itself under real
memory pressure) before assuming anything is actually broken. Only fall back to a native amd64
builder (the NAS, wired into `buildx` as a remote node) if retries are consistently failing.

## Run a sweep on the farm
    bin/desk sweep --queue --strategy=a1 --products=BTC-USD --days=30 --set=fees.taker_rate=0.0002 \
      --grid="a1.engine.x1=3,4,5,6" --grid="a1.tp_max_pct=2,3,4" --csv=/tmp/x.csv
    # rows land in the Backtests page like any other run; the command waits, then ranks.

Throughput (rough): M4 Max core ≈ 1 backtest-month of 2m bars / 20 s. Ultra ≈ 24 workers, Z890 ≈ 24–32,
Pi 5 ≈ 4 slow workers (≈ 1 Ultra core). 2 Ultras + 2 Z890s ≈ 100 combos in ~1 minute.

Queues: `backtests` = heavy jobs (Ultras/Z890 only), `backtests-light` = short jobs (Pis + everyone). Fast boxes listen on both; Pis only on `backtests-light` so a slow Pi never gates an optimizer round.

## Watchdog (Pi)
`ops/watchdog.sh` + `ops/shoemoneyx-watchdog.service` run on Pstan (<lan-host>): polls `http://<lan-host>:8811/api/status` every 30 s; after 3 misses (or ready=false / halted) it logs to `/var/log/shoemoneyx-watchdog.log` and, when `/etc/shoemoneyx-watchdog.env` has `TELEGRAM_BOT_TOKEN` + `TELEGRAM_CHAT_ID`, sends a Telegram message; one "RECOVERED" when it comes back. `journalctl -u shoemoneyx-watchdog -f`.

**Update:** the same Local Network check also bites pm2-launched `ssh` and tmux-parented processes on the desk host. Hold the hueb worker pool in a systemd user unit instead:

**Worker pool: hueb (<lan-host>)**

The pool is held by `farm-hueb.service` on Pi Pstan (<lan-host>, `ssh -i ~/.ssh/pi user@<worker-host>`):
- Unit file: `~/.config/systemd/user/farm-hueb.service` (Restart=always, RestartSec=10, linger enabled)
- Script: `/home/shoemoney/remote-workers.sh user@<worker-host> 24` (a copy of ops/remote-workers.sh)
- Log: `~/workers-hueb.log` on the Pi

Why: macOS Local Network privacy blocks LAN connections from launchd-parented processes (tmux server, pm2, nohup'd orphans). Only sshd-parented processes connect reliably, and even a detached process running on hueb itself is blocked. A Linux holder on the Pi has no such gate.

Operator commands (all on Pstan):
- `systemctl --user restart farm-hueb` after a deploy (the script git-pulls first)
- `journalctl --user -u farm-hueb -n 20` or `tail ~/workers-hueb.log`
- On hueb: `pgrep -f queue:work | wc -l` (expect 24–25); `pkill -f queue:work` if old workers survived a restart

**Never start a second holder on wick.**

## Watchdogs

`php artisan desk:rounds-watch` is a dead-man's switch for the optimizer farm — three checks, run in order:

- no `optimizer_rounds` row created in the last `--minutes` (default 30)
- Redis has jobs reserved/queued on `backtests` but `CLIENT LIST` shows zero connected workers (best-effort heuristic — see the command's docblock for what it can't see)
- any `backtests` row stuck `status=running` with `updated_at` older than 90 minutes (job timeout is 60 minutes)

This repo does have a scheduler (routes/console.php, run via `ROLE=schedule` -> `schedule:work` under docker/entrypoint.sh, with `DESK_USE_SCHEDULER` gating only the desk-loop entries), but `desk:rounds-watch` is deliberately *not* registered there. Run it every 5 minutes from the operator's own cron instead — do not wire it into Illuminate's Schedule. On a failed check it writes a `desk_events` row via `Reporter::warn()` (no Telegram — see the command's docblock for why) and self-remediates by calling `desk:release-orphans` with a `--since` cutoff appropriate to whichever condition fired.

## Deploy

Deployments handle pulling code, building if resources changed, running migrations, and restarting
the apps on each host — wick, reek, and hueb individually, or an image rebuild covering both arches
from a clean worktree, in whatever combination the deploy needs. Every `desk:release-orphans` call after a restart is scoped to the queue that
pool actually restarted, always run on wick, and named by the phase that failed if one does. The
paragraphs below describe the manual steps; read them when you need to understand a deployment step or
troubleshoot by hand.

**Scope the orphan release to the pool you restarted.** `desk:release-orphans --since=<restart time UTC>` re-queues *every* reservation older than the cutoff, on both queues by default. A Pi or NAS image swap only kills light-queue jobs, so after one run `php artisan desk:release-orphans --queues=backtests-light --since=…`; a wick/reek/hueb restart only kills heavy-queue jobs, so use `--queues=backtests`. Releasing the other queue with a short cutoff re-runs live jobs (2026-09-05 11:29: a one-minute cutoff after a Pi swap re-queued 38 heavy jobs the Macs were still running).



After restarting any worker pool (e.g., `systemctl --user restart farm-hueb` on the Pi holder), run `php artisan desk:release-orphans` on wick (checkout `/Users/shoemoney/shoemoneyx`). Redis `retry_after` is 4000 s (above the 3600 s job timeout), so jobs mid-flight when workers died sit reserved for over an hour; an optimizer round waits on them until its 40-minute deadline. The command re-queues every reservation taken before the restart with attempts reset. Use `--dry` to preview and `--since="YYYY-MM-DD HH:MM:SS"` (UTC) to scope to recent restarts.

## Worker pools

**Per-host pm2 start lines (the instance count and queue order live in env, and a `pm2 delete` + `pm2 start` loses them):**

- wick: `WORKERS=24 pm2 start ecosystem.config.cjs --only shoemoneyx-worker` (heavy-first, the default order `backtests,backtests-light`)
- reek: `WORKERS=16 WORKER_QUEUES=backtests-light,backtests pm2 start ecosystem.config.cjs --only shoemoneyx-worker` (light-first since 2026-09-05 11:45: the test-window leg on the Pis was bounding every round)
- reek also hosts three optimizers (wick's load sat at 24–29 on 28 cores while reek idled at 3 on 20, 2026-09-05 14:20): `pm2 start ecosystem.config.cjs --only shoemoneyx-reekopt-short-m1,shoemoneyx-reekopt-short-m6,shoemoneyx-reekopt-long-focus,shoemoneyx-reekopt-short-ret` (short-ret is `--rank=return --dry`: report-only, it asks whether any short set reaches 7.5 % train with a positive test when ranked by raw return; never let it promote alongside the Calmar-ranked optimizers or the two rankings ping-pong the same champion). The `shoemoneyx-reekopt-` prefix keeps them out of any bulk recreate of `shoemoneyx-optimizer*` on wick; after a reek pull, `pm2 delete` + that start line picks up new args. They do not appear in the /api/farm panel, which reads wick's pm2 only.
- `pm2 restart shoemoneyx-worker` keeps the env; only delete/start needs it again. `pm2 save` after either.


**hueb** (<lan-host>): Mac, 24 native `queue:work` processes from the `~/shoemoneyx` checkout (no container). Holder: systemd user unit on Pi Pstan (<lan-host>).

**NAS** (TrueNAS <lan-host>, x86_64): container `shoemoneyx-worker` from image `shoemoneyx:amd64`, 4 workers on queues `backtests,backtests-light`, env file at `~/shoemoneyx/worker.env` on the NAS; docker needs `sudo -n`.

The other eight Pis run image `shoemoneyx:local` (arm64) — `<lan-host>` is powered off or off the network since before 2026-09-05 09:00 (no ARP entry, no ping, ssh "Host is down"); needs a physical check. Both images are rebuilt with `docker buildx build --platform linux/arm64|linux/amd64` from a clean worktree only—never from a dirty working tree.
