<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Market\CandleStore;
use Illuminate\Console\Command;

class MarketBackfill extends Command
{
    protected $signature = 'market:backfill {products? : comma list (default: tracked)} {--days=30} {--timeframe=1H}';

    protected $description = 'Backfill candle history (for charts and backtests)';

    public function handle(CandleStore $store): int
    {
        $pids = $this->argument('products')
            ? array_map('trim', explode(',', (string) $this->argument('products')))
            : Product::tracked()->pluck('product_id')->all();
        $days = (int) $this->option('days');
        $tf = (string) $this->option('timeframe');

        foreach ($pids as $pid) {
            $n = $store->backfill(strtoupper($pid), $tf, $days);
            $this->line(sprintf('%-12s %s  +%d candles', $pid, $tf, $n));
        }

        return self::SUCCESS;
    }
}
