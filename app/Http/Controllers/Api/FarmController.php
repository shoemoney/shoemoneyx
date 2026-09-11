<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Backtest;
use App\Models\OptimizerRound;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * The one-panel replacement for `redis-cli client list | grep -c addr=…`, `LLEN`, `ZCARD`
 * and SQL: where the 214 optimizer workers across nine machines actually are, what the two
 * queues hold, how fast backtests are landing, and whether desk:rounds-watch is happy.
 */
class FarmController extends Controller
{
    private const QUEUES = ['backtests', 'backtests-light'];

    public function show(): JsonResponse
    {
        return response()->json(Cache::remember('farm:snapshot', 5, fn () => $this->snapshot()));
    }

    private function snapshot(): array
    {
        $redis = Redis::connection((string) config('queue.connections.redis.connection', 'default'));
        $clientRows = $this->clientListRows($redis);

        $queues = [];
        foreach (self::QUEUES as $name) {
            $queues[$name] = $this->queueStats($redis, $name);
        }

        return [
            'at' => now()->toIso8601String(),
            'hosts' => $this->hosts($clientRows),
            'queues' => $queues,
            'throughput' => $this->throughput(),
            'optimizers' => $this->optimizers(),
            'watch' => $this->watch(),
        ];
    }

    /**
     * `CLIENT LIST` as an array of field=>value rows, tolerant of both the raw newline string
     * some Redis clients hand back and the pre-split array phpredis can return. Best-effort:
     * an unreachable Redis or unparseable output degrades to no rows rather than a 500.
     *
     * @return array<int, array<string, string>>
     */
    private function clientListRows(mixed $redis): array
    {
        try {
            $raw = $redis->command('client', ['list']);
        } catch (\Throwable) {
            return [];
        }

        if (is_string($raw)) {
            $lines = array_filter(explode("\n", $raw), fn ($l) => trim($l) !== '');
            $raw = array_map(function (string $line): array {
                $fields = [];
                foreach (explode(' ', trim($line)) as $kv) {
                    [$key, $value] = array_pad(explode('=', $kv, 2), 2, null);
                    if ($key !== null) {
                        $fields[$key] = (string) $value;
                    }
                }

                return $fields;
            }, array_values($lines));
        }

        return is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
    }

    /**
     * @param  array<int, array<string, string>>  $clientRows
     * @return array<int, array<string, mixed>>
     */
    private function hosts(array $clientRows): array
    {
        $config = (array) config('farm.hosts', []);
        $perWorker = (float) config('farm.connections_per_worker', 2);
        $expectedMap = (array) config('farm.expected_workers', []);

        $deskIp = null;
        foreach ($config as $ip => $meta) {
            if (($meta['role'] ?? null) === 'desk') {
                $deskIp = (string) $ip;
                break;
            }
        }

        $connections = [];
        foreach ($clientRows as $fields) {
            $host = $this->addrHost($fields['addr'] ?? null);
            if ($host === null) {
                continue;
            }
            if ($deskIp !== null && in_array($host, ['127.0.0.1', '::1', 'localhost'], true)) {
                $host = $deskIp;
            }
            $connections[$host] = ($connections[$host] ?? 0) + 1;
        }

        $rows = [];
        foreach ($config as $ip => $meta) {
            $ip = (string) $ip;
            $count = $connections[$ip] ?? 0;
            unset($connections[$ip]);
            $expected = $expectedMap[$ip] ?? ($meta['role'] === 'pi' ? ($expectedMap['default'] ?? null) : null);
            $rows[] = $this->hostRow((string) $meta['label'], $ip, (string) $meta['role'], $count, $expected === null ? null : (int) $expected, $perWorker);
        }
        foreach ($connections as $ip => $count) {
            $rows[] = $this->hostRow($ip, $ip, 'unknown', $count, null, $perWorker);
        }

        return $rows;
    }

    /** @return array{label: string, ip: string, connections: int, workers_est: int, expected: int|null, role: string} */
    private function hostRow(string $label, string $ip, string $role, int $connections, ?int $expected, float $perWorker): array
    {
        $est = $perWorker > 0 ? (int) round($connections / $perWorker) : $connections;
        // 49 connections / 2 per worker rounds to 25, but hueb only runs 24 — snap down to the
        // expected count whenever the raw estimate overshoots it by less than 20%.
        if ($expected !== null && $est > $expected && $est <= $expected * 1.2) {
            $est = $expected;
        }

        return ['label' => $label, 'ip' => $ip, 'connections' => $connections, 'workers_est' => $est, 'expected' => $expected, 'role' => $role];
    }

    private function addrHost(?string $addr): ?string
    {
        if ($addr === null || $addr === '') {
            return null;
        }
        if (str_starts_with($addr, '[')) {
            return substr($addr, 1, (int) strpos($addr, ']') - 1);
        }
        $host = strstr($addr, ':', true);

        return $host === false ? $addr : $host;
    }

    /** @return array{queued: int, reserved: int, delayed: int, oldest_reserved_s: int|null} */
    private function queueStats(mixed $redis, string $name): array
    {
        $key = "queues:{$name}";
        $queued = (int) $redis->llen($key);
        $reserved = (int) $redis->zcard("{$key}:reserved");
        $delayed = (int) $redis->zcard("{$key}:delayed");

        $oldest = null;
        if ($reserved > 0) {
            $oldest = $this->oldestReservedSeconds($redis, $key);
        }

        return ['queued' => $queued, 'reserved' => $reserved, 'delayed' => $delayed, 'oldest_reserved_s' => $oldest];
    }

    private function oldestReservedSeconds(mixed $redis, string $key): ?int
    {
        $rows = $redis->zrange("{$key}:reserved", 0, 0, ['withscores' => true]);
        if (empty($rows)) {
            return null;
        }
        // reservedAt = score - retry_after (see DeskRoundsWatch); the sorted set's lowest score
        // (index 0) is the reservation due to expire soonest, i.e. the oldest one taken.
        $score = (int) array_values($rows)[0];
        $retryAfter = (int) config('queue.connections.redis.retry_after', 90);

        return max(0, now()->getTimestamp() - ($score - $retryAfter));
    }

    /**
     * `completed_at` is set once on the successful ("done") terminal transition and, unlike
     * `updated_at`, is never bumped by retention stripping curves/trades off old rows — so it's
     * the only column that means "finished" rather than "touched". `error` rows never get a
     * `completed_at` (there's no successful transition to stamp it), so `failed_1h` stays on
     * `updated_at` — the same heartbeat-style signal used before this change, just scoped to the
     * one status retention doesn't have a habit of re-touching. Window boundaries are explicit
     * UTC instants (the app already runs in UTC, but a dashboard reading "throughput" should
     * never depend on that being true forever). The done-side numbers come from one
     * conditional-aggregate query plus a cheap `computed_5m` breakout — no per-row JSON is ever
     * pulled into PHP.
     *
     * @return array{done_1m: int, done_5m: int, computed_5m: int, reused_5m: int, running: int, failed_1h: int, under_one_contract_5m: int}
     */
    private function throughput(): array
    {
        $now = Carbon::now('UTC');
        $oneMinuteAgo = $now->clone()->subMinute();
        $fiveMinutesAgo = $now->clone()->subMinutes(5);
        $oneHourAgo = $now->clone()->subHour();

        // Scoped to done rows completed in the last 5 minutes — an index range on
        // (status,completed_at) — so every row left standing is already known to be within the
        // window and done_1m only needs to narrow that further.
        $agg = Backtest::query()
            ->where('status', 'done')->where('completed_at', '>=', $fiveMinutesAgo)
            ->selectRaw(
                'SUM(CASE WHEN completed_at >= ? THEN 1 ELSE 0 END) AS done_1m, COUNT(*) AS done_5m, SUM(under_one_contract) AS under_one_contract_5m',
                [$oneMinuteAgo]
            )
            ->first();

        // A cache-hit result (DeskOptimize::reuseOrQueue) is flagged by params._opt.cached_from on
        // the row it cloned from; everything else in the 5m window was actually computed.
        $computed5m = Backtest::where('status', 'done')->where('completed_at', '>=', $fiveMinutesAgo)
            ->whereNull('params->_opt->cached_from')->count();

        $running = Backtest::where('status', 'running')->count();
        $failed1h = Backtest::where('status', 'error')->where('updated_at', '>=', $oneHourAgo)->count();
        $done5m = (int) ($agg->done_5m ?? 0);

        return [
            'done_1m' => (int) ($agg->done_1m ?? 0),
            'done_5m' => $done5m,
            'computed_5m' => $computed5m,
            'reused_5m' => max(0, $done5m - $computed5m),
            'running' => $running,
            'failed_1h' => $failed1h,
            'under_one_contract_5m' => (int) ($agg->under_one_contract_5m ?? 0),
        ];
    }

    /**
     * Groups the last hour of optimizer_rounds by (side, cash). The fast optimizer runs
     * long/10000 too, so it shares the "shoemoneyx-optimizer" name with the main one here — that's
     * fine, both feed the same champion set and the panel only needs to show the group is alive.
     *
     * @return array<int, array{name: string, side: string, cash: float, last_round_at: string|null, rounds_1h: int, promoted_1h: int}>
     */
    private function optimizers(): array
    {
        // Every group here is built from rows inside the same lower-bounded window, so each
        // group's own MAX(created_at) is guaranteed to already sit inside that window too —
        // one grouped query replaces the former one-MAX-query-per-group fan-out.
        $groups = OptimizerRound::query()
            ->selectRaw('tag, side, cash, count(*) as rounds_1h, sum(case when promoted then 1 else 0 end) as promoted_1h, max(created_at) as last_round_at')
            ->where('created_at', '>=', now()->subHour())
            ->groupBy('tag', 'side', 'cash')
            ->get();

        $out = [];
        foreach ($groups as $g) {
            $tag = $g->tag !== null ? (string) $g->tag : null;
            $side = (string) $g->side;
            $cash = (float) $g->cash;
            $out[] = [
                'name' => $this->optimizerName($side, $cash, $tag),
                'tag' => $tag,
                'side' => $side,
                'cash' => $cash,
                'last_round_at' => $g->last_round_at ? Carbon::parse($g->last_round_at)->toIso8601String() : null,
                'rounds_1h' => (int) $g->rounds_1h,
                'promoted_1h' => (int) $g->promoted_1h,
            ];
        }

        return $out;
    }

    /** Tagged rounds name their process directly (every optimizer runs --tag=<suffix>); untagged history keeps the side/cash mapping. */
    private function optimizerName(string $side, float $cash, ?string $tag = null): string
    {
        if ($tag !== null && $tag !== '') {
            return $tag === 'long' ? 'shoemoneyx-optimizer' : "shoemoneyx-optimizer-{$tag}";
        }

        return match (true) {
            $side === 'long' && $cash === 10000.0 => 'shoemoneyx-optimizer',
            $side === 'short' && $cash === 10000.0 => 'shoemoneyx-optimizer-short',
            $side === 'long' && $cash === 3000.0 => 'shoemoneyx-optimizer-small',
            default => "{$side}@{$cash}",
        };
    }

    /** @return array{at: string|null, lines: array<int, string>} */
    private function watch(): array
    {
        $path = storage_path('logs/rounds-watch.log');
        if (! is_file($path)) {
            return ['at' => null, 'lines' => []];
        }

        $lines = explode("\n", rtrim((string) file_get_contents($path), "\n"));
        $lines = array_slice($lines, -40);
        // One run = the three checks starting at fresh-round (plus any remediation lines); cron appends runs back to back.
        $start = null;
        foreach ($lines as $k => $line) {
            if (str_contains($line, 'fresh-round')) {
                $start = $k;
            }
        }
        $lines = $start !== null ? array_slice($lines, $start) : [];
        $lines = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));

        return ['at' => Carbon::createFromTimestamp(filemtime($path))->toIso8601String(), 'lines' => $lines];
    }
}
