<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Desk\Strategies\BacktestVersionPin;
use App\Models\Backtest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Grid-search a strategy's parameters. Each combination is a normal backtest
 * (rows land on the Backtests page too). Runs N in parallel.
 *
 *   php artisan desk:sweep --strategy=mr --products=BTC-USD --days=30 \
 *     --grid="mr.entry_z=1.5,2,2.5" --grid="mr.exit_z=0.2,0.3,0.5" \
 *     --grid="mr.lookback=30,60,90" --set=fees.taker_rate=0 --set=paper.slippage_bps=0
 *
 * --queue: every combination becomes a RunBacktest job on the "backtests" queue. Workers on any
 * machine that shares this Redis + MySQL (bin/desk worker 24) run them; this command waits and ranks.
 */
class DeskSweep extends Command
{
    protected $signature = 'desk:sweep {--strategy=mr} {--products=} {--days=30} {--cash=10000} {--grid=* : key=v1,v2,v3} {--set=* : fixed overrides key=value} {--parallel=4} {--queue : dispatch to the backtests queue (remote workers: bin/desk worker N)} {--sort=total_return_pct} {--csv= : write results here}';

    protected $description = 'Backtest every combination of the given parameter grid, ranked';

    public function handle(): int
    {
        $grid = [];
        foreach ((array) $this->option('grid') as $g) {
            [$k, $vals] = array_pad(explode('=', $g, 2), 2, '');
            $grid[trim($k)] = array_map('trim', explode(',', $vals));
        }
        if ($grid === []) {
            $this->error('give at least one --grid=key=a,b,c');

            return self::FAILURE;
        }

        $combos = [[]];
        foreach ($grid as $k => $vals) {
            $next = [];
            foreach ($combos as $c) {
                foreach ($vals as $v) {
                    $next[] = $c + [$k => $v];
                }
            }
            $combos = $next;
        }
        $this->info(count($combos).' combinations × '.$this->option('days').' days, '.$this->option('parallel').' in parallel');

        $fixed = array_map(fn ($s) => '--set='.$s, (array) $this->option('set'));
        $base = ['php', base_path('artisan'), 'desk:backtest', '--strategy='.$this->option('strategy'), '--days='.$this->option('days'), '--cash='.$this->option('cash'), '--no-fetch', ...$fixed];
        if ($this->option('products')) {
            $base[] = '--products='.$this->option('products');
        }

        $results = [];
        $before = (int) (Backtest::max('id') ?? 0);
        $bar = $this->output->createProgressBar(count($combos));

        if ($this->option('queue')) {
            $ids = $this->dispatchAll($combos);
            $this->info(count($ids).' jobs queued on "backtests" — waiting for workers');
            while (true) {
                $done = Backtest::whereIn('id', $ids)->whereIn('status', ['done', 'error'])->count();
                $bar->setProgress($done);
                if ($done >= count($ids)) {
                    break;
                }
                sleep(3);
            }
            $bar->finish();
            $this->newLine();
            $rows = Backtest::whereIn('id', $ids)->where('status', 'done')->get();

            return $this->report($rows, $grid);
        }

        foreach (array_chunk($combos, (int) $this->option('parallel')) as $chunk) {
            $pool = Process::pool(function ($pool) use ($chunk, $base) {
                foreach ($chunk as $combo) {
                    $args = $base;
                    foreach ($combo as $k => $v) {
                        $args[] = "--set={$k}={$v}";
                    }
                    $pool->command($args)->timeout(1800);
                }
            })->start();
            $pool->wait();
            $bar->advance(count($chunk));
        }
        $bar->finish();
        $this->newLine();

        $rows = Backtest::where('id', '>', $before)->where('status', 'done')->get();

        return $this->report($rows, $grid);
    }

    /** One queued Backtest row + job per combination. Fixed --set overrides are merged into params. */
    private function dispatchAll(array $combos): array
    {
        $fixed = [];
        foreach ((array) $this->option('set') as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
            $fixed[trim($k)] = self::cast(trim($v));
        }
        $products = $this->option('products') ? array_map('trim', explode(',', (string) $this->option('products'))) : \App\Models\Product::tracked()->orderByDesc('volume_24h_usd')->limit(20)->pluck('product_id')->all();
        $to = now()->startOfHour();
        $from = $to->copy()->subDays((int) $this->option('days'));
        $strategy = (string) $this->option('strategy');
        $cash = (float) $this->option('cash');
        $ids = [];
        foreach ($combos as $combo) {
            $params = $fixed;
            foreach ($combo as $k => $v) {
                $params[$k] = self::cast((string) $v);
            }
            $ids[] = self::queueOne($strategy, $params, $products, $from, $to, $cash)->id;
        }

        return $ids;
    }

    /**
     * One combination -> one queued Backtest row + RunBacktest job. A JSON plugin (strategy=json,
     * params['json.plugin_key'] set) pins the exact version at dispatch time via BacktestVersionPin
     * — a later edit to the plugin must not change what an already-queued combination runs. Public
     * static so a test can assert the pinning directly, the same seam DeskOptimize::reuseOrQueue is.
     */
    public static function queueOne(string $strategy, array $params, array $products, \Carbon\Carbon $from, \Carbon\Carbon $to, float $cash): Backtest
    {
        [$versionId, $params] = BacktestVersionPin::resolve($strategy, $params);
        $bt = Backtest::create([
            'strategy' => $strategy, 'strategy_plugin_version_id' => $versionId, 'products' => $products, 'from' => $from, 'to' => $to,
            'starting_cash' => $cash, 'params' => $params, 'status' => 'queued',
        ]);
        \App\Jobs\RunBacktest::dispatch($bt->id)->onQueue('backtests');

        return $bt;
    }

    private static function cast(string $v): mixed
    {
        return match (true) {
            $v === 'true' => true,
            $v === 'false' => false,
            is_numeric($v) => $v + 0,
            default => $v,
        };
    }

    private function report($rows, array $grid): int
    {
        $results = [];
        foreach ($rows as $bt) {
            $s = $bt->stats ?? [];
            $p = [];
            foreach ($grid as $k => $_) {
                $p[$k] = $bt->params[$k] ?? data_get($bt->params, $k);
            }
            $results[] = ['id' => $bt->id] + $p + [
                'return_pct' => $s['total_return_pct'] ?? null,
                'trades' => $s['trades'] ?? null,
                'win_rate' => $s['win_rate'] ?? null,
                'profit_factor' => $s['profit_factor'] ?? null,
                'max_dd_pct' => $s['max_drawdown_pct'] ?? null,
                'avg_hold_h' => $s['avg_hold_hours'] ?? null,
                'trims' => $s['trims'] ?? null,
            ];
        }
        $sortKey = ['total_return_pct' => 'return_pct', 'profit_factor' => 'profit_factor', 'win_rate' => 'win_rate'][$this->option('sort')] ?? 'return_pct';
        usort($results, fn ($a, $b) => ($b[$sortKey] ?? -INF) <=> ($a[$sortKey] ?? -INF));

        if ($results === []) {
            $this->error('no completed backtests — check storage/logs for errors');

            return self::FAILURE;
        }
        $this->table(array_keys($results[0]), array_map(fn ($r) => array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $r), $results));

        if ($csv = $this->option('csv')) {
            $fh = fopen($csv, 'w');
            fputcsv($fh, array_keys($results[0]));
            foreach ($results as $r) {
                fputcsv($fh, $r);
            }
            fclose($fh);
            $this->info("wrote {$csv}");
        }

        return self::SUCCESS;
    }
}
