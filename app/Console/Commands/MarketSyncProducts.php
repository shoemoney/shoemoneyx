<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Market\ProductSync;
use Illuminate\Console\Command;

class MarketSyncProducts extends Command
{
    protected $signature = 'market:sync-products';

    protected $description = 'Pull the Coinbase SPOT product list and pick the tracked universe';

    public function handle(ProductSync $sync): int
    {
        $r = $sync->sync();
        $this->info(sprintf('synced %d products (%d new), tracking %d', $r['synced'], $r['new'], $r['tracked']));

        return self::SUCCESS;
    }
}
