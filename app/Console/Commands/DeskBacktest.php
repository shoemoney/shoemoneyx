<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Desk\Backtester;
use App\Desk\Settings;
use App\Models\Product;
use App\Services\Market\CandleStore;
use Carbon\Carbon;
use Illuminate\Console\Command;

class DeskBacktest extends Command
{
    protected $signature = 'desk:backtest {--strategy=} {--products= : comma list (default: top 20 tracked)} {--days=30} {--from=} {--to=} {--cash=1000} {--set=* : param overrides key=value} {--no-fetch : do not backfill candles first}';

    protected $description = 'Replay a strategy over stored 1H candles';

    public function handle(Backtester $bt, Settings $settings, CandleStore $store): int
    {
        $strategy = (string) ($this->option('strategy') ?: $settings->strategyKey());
        $pids = $this->option('products')
            ? array_map(fn ($s) => strtoupper(trim($s)), explode(',', (string) $this->option('products')))
            : Product::tracked()->orderByDesc('volume_24h_usd')->limit(20)->pluck('product_id')->all();
        $to = $this->option('to') ? Carbon::parse((string) $this->option('to')) : now()->startOfHour();
        $from = $this->option('from') ? Carbon::parse((string) $this->option('from')) : $to->copy()->subDays((int) $this->option('days'));
        $overrides = [];
        foreach ((array) $this->option('set') as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, null);
            $overrides[$k] = is_numeric($v) ? $v + 0 : (in_array(strtolower((string) $v), ['true', 'false'], true) ? strtolower((string) $v) === 'true' : $v);
        }

        if (! $this->option('no-fetch')) {
            $this->line('backfilling candles…');
            foreach ($pids as $pid) {
                try {
                    $store->sync($pid, '1H', $from->getTimestamp() - 50 * 3600);
                } catch (\Throwable $e) {
                    $this->warn("{$pid}: ".$e->getMessage());
                }
            }
        }

        $this->line(sprintf('backtest %s on %d products %s → %s cash $%s', $strategy, count($pids), $from->toDateTimeString(), $to->toDateTimeString(), $this->option('cash')));
        $result = $bt->run($strategy, $pids, $from, $to, (float) $this->option('cash'), $overrides, function ($t, $eq, $n) {
            $this->line(sprintf('  %s  equity $%.2f  trades %d', $t->toDateString(), $eq, $n));
        });

        $this->newLine();
        $this->table(['metric', 'value'], collect($result->stats)->map(fn ($v, $k) => [$k, is_array($v) ? json_encode($v) : $v])->values()->all());
        $this->info(sprintf('backtest #%d: $%.2f → $%.2f', $result->id, $result->starting_cash, $result->ending_equity));

        return self::SUCCESS;
    }
}
