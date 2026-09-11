<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Position;
use App\Models\Product;
use App\Services\Market\CandleStore;
use Illuminate\Console\Command;

class MarketSyncCandles extends Command
{
    protected $signature = 'market:sync-candles {--timeframes=1H,1D} {--hours=72 : how far back to guarantee} {--products= : comma list, default tracked + open}';

    protected $description = 'Fetch missing candles for tracked products into MySQL';

    public function handle(CandleStore $store): int
    {
        $tfs = array_filter(array_map('trim', explode(',', (string) $this->option('timeframes'))));
        $hours = (int) $this->option('hours');
        $pids = $this->option('products')
            ? array_map('trim', explode(',', (string) $this->option('products')))
            : Product::tracked()->pluck('product_id')->merge(Position::open()->pluck('product_id'))->unique()->values()->all();

        $total = 0;
        $bar = $this->output->createProgressBar(count($pids) * count($tfs));
        foreach ($pids as $pid) {
            foreach ($tfs as $tf) {
                try {
                    $total += $store->sync($pid, $tf, time() - $hours * 3600);
                } catch (\Throwable $e) {
                    $this->newLine();
                    $this->warn("{$pid} {$tf}: ".$e->getMessage());
                }
                $bar->advance();
            }
        }
        $bar->finish();
        $this->newLine();
        $this->info("upserted {$total} candles across ".count($pids).' products');

        return self::SUCCESS;
    }
}
