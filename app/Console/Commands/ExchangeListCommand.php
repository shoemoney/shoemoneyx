<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exchange\ExchangeRegistry;
use Illuminate\Console\Command;

class ExchangeListCommand extends Command
{
    protected $signature = 'exchange:list';

    protected $description = 'List every registered exchange adapter and what it supports';

    public function handle(ExchangeRegistry $registry): int
    {
        $rows = [];
        foreach ($registry->all() as $id) {
            try {
                $exchange = $registry->make($id);
                $caps = $exchange->capabilities();
            } catch (\Throwable $e) {
                $rows[] = [$id, 'unavailable: '.$e->getMessage(), '', '', '', '', ''];

                continue;
            }

            $yes = fn (bool $v) => $v ? 'yes' : 'no';
            $rows[] = [
                $id,
                $exchange->name(),
                $yes($caps->spot),
                $yes($caps->perps),
                $yes($caps->websocket),
                $yes($caps->historicalTrades),
                $yes($caps->shorts),
            ];
        }

        $this->table(['id', 'name', 'spot', 'perps', 'websocket', 'historical_trades', 'shorts'], $rows);

        return self::SUCCESS;
    }
}
