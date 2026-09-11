<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Market\TradeBackfill;
use Illuminate\Console\Command;

class MarketBackfillTrades extends Command
{
    protected $signature = 'market:backfill-trades
        {products? : comma list; omit with --all-active}
        {--all-active : target every product in config(desk.perps.active) instead of {products}}
        {--days=7}
        {--timeframes=15s,30s,45s : subset of Candle::FROM_TRADES}';

    protected $description = 'Rebuild sub-minute candles from Coinbase historical trades. '
        .'Depth verified live on 2026-09-05: /products/{id}/ticker windowed by start/end is NOT capped to the '
        .'most recent N trades — it returned genuine history for BTC-USD back to 2021 (empty before the product '
        .'existed), so --days is bounded only by wall-clock time and the REST rate limit, not by an API recency '
        .'cutoff. Idempotent: rows upsert on (product_id, timeframe, candle_start), so an overlapping window just '
        .'recomputes the same OHLCV — a cron can run a cheap --days=1 every 30 minutes to extend coverage forward '
        .'without redoing the original deep seed.';

    public function handle(TradeBackfill $bf): int
    {
        $tfs = array_map('trim', explode(',', (string) $this->option('timeframes')));
        $from = time() - (int) $this->option('days') * 86400;

        if ($this->option('all-active')) {
            $products = (array) config('desk.perps.active');
        } else {
            $products = array_filter(array_map('trim', explode(',', (string) $this->argument('products'))));
        }
        if ($products === []) {
            $this->error('No products: pass {products} or --all-active with desk.perps.active configured.');

            return self::FAILURE;
        }

        foreach ($products as $pid) {
            $pid = strtoupper($pid);
            $this->line("{$pid}: walking trades back ".$this->option('days').' days …');
            $r = $bf->run($pid, $from, time(), $tfs, fn ($calls, $trades, $at) => $this->output->write(sprintf("\r  %d calls, %d trades, at %s", $calls, $trades, gmdate('m-d H:i', $at))));
            $this->newLine();
            $reached = $r['earliest'] !== null ? gmdate('Y-m-d H:i:s', $r['earliest']).' UTC' : 'n/a (no trades in range)';
            $this->info(sprintf('  %s: %d trades → %d candles in %d calls, reached back to %s', $pid, $r['trades'], $r['candles'], $r['calls'], $reached));
        }

        return self::SUCCESS;
    }
}
