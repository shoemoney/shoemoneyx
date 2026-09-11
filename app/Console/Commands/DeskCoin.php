<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Desk\Settings;
use Illuminate\Console\Command;

/**
 * Per-coin parameters (the `per_product` layer). Each coin can run its own timeframe / engine / ladder.
 *
 *   php artisan desk:coin show
 *   php artisan desk:coin set BTC-USD mr.timeframe=2m mr.entry_z=2.5 mr.lookback=90
 *   php artisan desk:coin clear BTC-USD
 *
 * Keys are the same dotted names as global settings; they override the global value for that coin only.
 */
class DeskCoin extends Command
{
    protected $signature = 'desk:coin {action : show|set|clear} {product?} {kv?* : key=value pairs}';

    protected $description = 'Show / set / clear per-coin parameter overrides';

    public function handle(Settings $settings): int
    {
        $action = $this->argument('action');
        $pid = strtoupper((string) $this->argument('product'));

        if ($action === 'show') {
            $all = (array) $settings->get('per_product', []);
            if ($pid !== '') {
                $all = array_intersect_key($all, [$pid => 1]);
            }
            if ($all === []) {
                $this->line('no per-coin overrides');

                return self::SUCCESS;
            }
            $rows = [];
            foreach ($all as $p => $o) {
                foreach (\Illuminate\Support\Arr::dot($o) as $k => $v) {
                    $rows[] = [$p, $k, is_bool($v) ? var_export($v, true) : $v];
                }
            }
            $this->table(['coin', 'key', 'value'], $rows);

            return self::SUCCESS;
        }

        if ($pid === '') {
            $this->error('product required');

            return self::FAILURE;
        }

        if ($action === 'clear') {
            $settings->forgetTree("per_product.{$pid}");
            $this->info("{$pid}: overrides cleared");

            return self::SUCCESS;
        }

        if ($action === 'set') {
            $n = 0;
            foreach ((array) $this->argument('kv') as $kv) {
                [$k, $v] = array_pad(explode('=', $kv, 2), 2, null);
                if ($v === null) {
                    continue;
                }
                $v = match (true) {
                    $v === 'true' => true,
                    $v === 'false' => false,
                    is_numeric($v) => $v + 0,
                    default => $v,
                };
                $settings->set("per_product.{$pid}.".trim($k), $v);
                $n++;
            }
            $this->info("{$pid}: {$n} override(s) set");
            $this->call('desk:coin', ['action' => 'show', 'product' => $pid]);

            return self::SUCCESS;
        }

        $this->error('action must be show|set|clear');

        return self::FAILURE;
    }
}
