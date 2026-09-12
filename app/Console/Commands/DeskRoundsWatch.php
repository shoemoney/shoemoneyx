<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Desk\Reporter;
use App\Models\Backtest;
use App\Models\OptimizerRound;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;

/**
 * Dead-man's switch for the optimizer farm: is a round landing, are workers actually
 * connected, is anything wedged in "running". Invoked every 5 minutes via cron or
 * `ROLE=schedule` if `DESK_USE_SCHEDULER=true` (see routes/console.php). Do not wire
 * this into Illuminate's Schedule; invoke from cron instead.
 *
 * Each condition is its own named check (name + closure) executed in sequence, rather than
 * one long straight-line command, so a single failure mode can be read and tested in
 * isolation. Every failed check is reported via Reporter::warn() (desk_events row only, no
 * Telegram) — this command is expected to blip transiently every few cycles while a round is
 * mid-flight, and self-heals via desk:release-orphans, so it is not page-worthy the way
 * Reporter::error() is.
 */
class DeskRoundsWatch extends Command
{
    protected $signature = 'desk:rounds-watch {--minutes=30} {--min-workers=1} {--coin-minutes=120 : every active coin and side must have had a round this recently}';

    protected $description = "Dead-man's switch: fresh optimizer rounds, connected workers, no backtests stuck running";

    /** Set by checkStuckBacktests(), read by remediate(). */
    private int $stuckBacktests = 0;

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $allPass = true;

        foreach ($this->checks($minutes) as $check) {
            [$pass, $message] = $check['fn']();
            $line = sprintf('[%s] %s: %s', $pass ? 'PASS' : 'FAIL', $check['name'], $message);
            $this->line($pass ? $line : "<fg=red>{$line}</>");

            if (! $pass) {
                $allPass = false;
                app(Reporter::class)->warn('WATCH', $message);
            }
        }

        $this->remediate();

        return $allPass ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<int, array{name: string, fn: callable(): array{0: bool, 1: string}}>
     */
    private function checks(int $minutes): array
    {
        return [
            ['name' => 'fresh-round', 'fn' => fn () => $this->checkFreshRound($minutes)],
            ['name' => 'workers-alive', 'fn' => fn () => $this->checkWorkersAlive()],
            ['name' => 'stuck-backtests', 'fn' => fn () => $this->checkStuckBacktests()],
            ['name' => 'stale-coins', 'fn' => fn () => $this->checkStaleCoins((int) $this->option('coin-minutes'))],
        ];
    }

    /**
     * Every active coin's champion is under challenge on both sides, always (Jeremy, 2026-09-05): a coin/side with no
     * round inside the window means an optimizer stopped cycling it or nothing targets it.
     *
     * @return array{0: bool, 1: string}
     */
    private function checkStaleCoins(int $minutes): array
    {
        $coins = array_values((array) config('desk.perps.active', []));
        if ($coins === []) {
            return [true, 'no active coin list configured, skipped'];
        }
        $recent = OptimizerRound::query()
            ->selectRaw('product_id, side, max(created_at) as last_at')
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->groupBy('product_id', 'side')
            ->get()
            ->keyBy(fn ($r) => $r->product_id.'|'.$r->side);
        $stale = [];
        foreach ($coins as $coin) {
            foreach (['long', 'short'] as $side) {
                if (! $recent->has($coin.'|'.$side)) {
                    $stale[] = "{$coin} {$side}";
                }
            }
        }
        if ($stale === []) {
            return [true, count($coins)." coins × 2 sides all challenged in the last {$minutes}m"];
        }

        return [false, count($stale)." coin/sides without a round in {$minutes}m: ".implode(', ', array_slice($stale, 0, 8)).(count($stale) > 8 ? ' …' : '')];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function checkFreshRound(int $minutes): array
    {
        $exists = OptimizerRound::where('created_at', '>=', now()->subMinutes($minutes))->exists();

        return $exists
            ? [true, "an optimizer round landed in the last {$minutes}m"]
            : [false, "no optimizer round created in the last {$minutes}m"];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function checkWorkersAlive(): array
    {
        // Faking phpredis/predis's CLIENT LIST output reliably isn't worth the complexity for
        // a health check test — see DeskRoundsWatchTest for the same reasoning.
        if (app()->runningUnitTests()) {
            $this->comment('workers-alive: skipped under unit tests (CLIENT LIST not faked)');

            return [true, 'skipped under unit tests'];
        }

        $redis = Redis::connection((string) config('queue.connections.redis.connection', 'default'));
        $reservedRaw = $redis->zrange('queues:backtests:reserved', 0, -1, ['withscores' => true]);
        $queued = (int) $redis->llen('queues:backtests');
        $pending = count($reservedRaw) + $queued;

        if ($pending === 0) {
            return [true, 'nothing reserved or queued'];
        }

        $minWorkers = max(1, (int) $this->option('min-workers'));

        try {
            $rows = $redis->command('client', ['list']);
        } catch (\Throwable $e) {
            // Best-effort: CLIENT LIST parsing is a heuristic (see below), an infra hiccup
            // here shouldn't fail the whole command.
            $this->comment("workers-alive: CLIENT LIST unavailable ({$e->getMessage()}), skipped as pass");

            return [true, 'CLIENT LIST unavailable, skipped'];
        }

        $workers = $this->parseClientList($rows);
        if ($workers === null) {
            $this->comment('workers-alive: CLIENT LIST output unparseable, skipped as pass');

            return [true, 'CLIENT LIST unparseable, skipped'];
        }

        if ($workers < $minWorkers) {
            return [false, "{$pending} reserved+queued, {$workers} workers connected"];
        }

        return [true, "{$pending} reserved+queued, {$workers} workers connected"];
    }

    /**
     * Best-effort worker detection from `CLIENT LIST` output: a connection counts as a worker
     * when its `name=` contains "worker", or its `addr=` host isn't loopback. This is a known
     * limitation, not a bug to fix here — it cannot see workers arriving through a proxy/tunnel
     * where every connection appears to originate from 127.0.0.1 (true for hueb's systemd-held
     * pool and any SSH-tunnelled worker), and counting `queue:work` processes via `pm2 jlist` is
     * out of scope: worker pools here run outside pm2 (see docs/FARM.md's systemd/native setups).
     *
     * @return int|null worker count, null if unparseable
     */
    private function parseClientList(mixed $rows): ?int
    {
        if (is_string($rows)) {
            $rows = array_map(function (string $line): array {
                $fields = [];
                foreach (explode(' ', trim($line)) as $kv) {
                    [$key, $value] = array_pad(explode('=', $kv, 2), 2, null);
                    if ($key !== null) {
                        $fields[$key] = $value;
                    }
                }

                return $fields;
            }, array_filter(explode("\n", $rows), fn ($l) => trim($l) !== ''));
        }
        if (! is_array($rows)) {
            return null;
        }
        $workers = 0;
        foreach ($rows as $fields) {
            if (! is_array($fields)) {
                continue;
            }
            $isWorker = (isset($fields['name']) && str_contains((string) $fields['name'], 'worker'))
                || ! $this->isLocalAddr($fields['addr'] ?? null);
            if ($isWorker) {
                $workers++;
            }
        }

        return $workers;
    }

    private function isLocalAddr(?string $addr): bool
    {
        if ($addr === null || $addr === '') {
            return false;
        }

        $host = str_starts_with($addr, '[')
            ? substr($addr, 1, (int) strpos($addr, ']') - 1)
            : strstr($addr, ':', true);

        return in_array($host === false ? $addr : $host, ['127.0.0.1', '::1', 'localhost'], true);
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function checkStuckBacktests(): array
    {
        $count = Backtest::where('status', 'running')
            ->where('updated_at', '<', now()->subMinutes(90))
            ->count();

        $this->stuckBacktests = $count;

        return $count === 0
            ? [true, 'no backtests stuck in running > 90m']
            : [false, "{$count} backtests stuck in running > 90m"];
    }

    /**
     * Re-queues reservations older than the 90-minute stuck threshold, and only when a backtest row is
     * actually wedged: a live job may legitimately run up to the 60-minute timeout, so releasing on any
     * shorter cutoff (or on "older than the newest worker connection") would run live jobs twice.
     */
    private function remediate(): void
    {
        if ($this->stuckBacktests === 0) {
            return;
        }
        $cutoff = now()->subMinutes(90);
        $reason = 'stuck backtests';
        $since = $cutoff->format('Y-m-d H:i:s');
        $this->comment("remediating ({$reason}): desk:release-orphans --since=\"{$since}\"");

        try {
            Artisan::call('desk:release-orphans', ['--since' => $since]);
            $this->line(Artisan::output());
        } catch (\Throwable $e) {
            // A missing/unreachable Redis here shouldn't turn a health-check run into a crash.
            $this->comment("remediation skipped, desk:release-orphans failed: {$e->getMessage()}");
        }
    }
}
