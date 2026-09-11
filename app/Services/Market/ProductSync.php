<?php

declare(strict_types=1);

namespace App\Services\Market;

use App\Exchange\Contracts\MarketData;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/** Pulls the exchange's SPOT product list into `products` and picks the tradeable universe. */
class ProductSync
{
    public function __construct(private MarketData $market) {}

    /** @return array{synced:int,new:int,tracked:int} */
    public function sync(): array
    {
        $rows = $this->market->products();
        $quotes = config('desk.universe.quote_currencies', ['USD']);
        $exclude = config('desk.universe.exclude', []);
        $minVol = (float) config('desk.universe.min_volume_24h_usd', 0);
        $maxProducts = (int) config('desk.universe.max_products', 120);
        $stableBases = config('desk.universe.stable_bases', ['USDT', 'USDC', 'DAI', 'PAX', 'GUSD', 'PYUSD', 'EURC', 'USD1', 'RLUSD', 'FDUSD', 'TUSD', 'BUSD', 'USDS', 'USDE']);

        $now = now();
        $new = 0;
        $synced = 0;
        $candidates = [];

        foreach ($rows as $r) {
            $pid = $r['product_id'] ?? null;
            $raw = $r['raw'] ?? [];
            if (! $pid || ($raw['product_type'] ?? 'SPOT') !== 'SPOT') {
                continue;
            }
            $quote = strtoupper($r['quote_currency'] ?? '');
            if (! in_array($quote, $quotes, true) || in_array($pid, $exclude, true)) {
                continue;
            }

            $volUsd = (float) ($r['volume_24h_usd'] ?? 0);
            $status = (string) ($r['status'] ?? 'online');
            $disabled = (bool) ($r['trading_disabled'] ?? false);

            $existing = Product::where('product_id', $pid)->first();
            $attrs = [
                'base_currency' => strtoupper($r['base_currency'] ?? ''),
                'quote_currency' => $quote,
                'status' => $status,
                'price' => $r['price'] ?: null,
                'price_change_24h_pct' => $r['price_change_24h_pct'] ?? null,
                'volume_24h' => $r['volume_24h'] ?? 0,
                'volume_24h_usd' => $volUsd,
                'base_increment' => $r['base_increment'] ?? null,
                'quote_increment' => $r['quote_increment'] ?? null,
                'quote_min_size' => $r['quote_min_size'] ?? null,
                'base_min_size' => $r['base_min_size'] ?? null,
                'trading_disabled' => $disabled,
                'synced_at' => $now,
                'meta' => [
                    'base_name' => $raw['base_name'] ?? null,
                    'display_name' => $raw['display_name'] ?? null,
                    'new_at_coinbase' => $raw['new'] ?? null,
                ],
            ];

            if ($existing) {
                $existing->fill($attrs)->save();
            } else {
                $attrs['product_id'] = $pid;
                // First sighting. Coinbase flags brand-new listings with `new`; otherwise assume it is old.
                $attrs['listed_at'] = ($raw['new'] ?? false) ? $now : null;
                $existing = Product::create($attrs);
                $new++;
            }
            $synced++;

            $base = strtoupper($r['base_currency'] ?? '');
            if ($status === 'online' && ! $disabled && $volUsd >= $minVol && ! in_array($base, $stableBases, true)) {
                // One market per base asset: prefer the quote listed first in universe.quote_currencies.
                $pref = array_search($quote, $quotes, true);
                if (! isset($candidates[$base]) || $pref < $candidates[$base]['pref']) {
                    $candidates[$base] = ['pid' => $pid, 'vol' => $volUsd, 'pref' => $pref];
                }
            }
        }

        // Track the top-N by USD volume (+ anything with an open position, handled by the desk).
        uasort($candidates, fn ($a, $b) => $b['vol'] <=> $a['vol']);
        $tracked = array_slice(array_column($candidates, 'pid'), 0, $maxProducts);

        DB::transaction(function () use ($tracked) {
            Product::query()->where('is_tracked', true)->whereNotIn('product_id', $tracked)
                ->whereNotIn('product_id', fn ($q) => $q->select('product_id')->from('positions')->where('status', 'open'))
                ->update(['is_tracked' => false]);
            Product::query()->whereIn('product_id', $tracked)->update(['is_tracked' => true]);
        });

        return ['synced' => $synced, 'new' => $new, 'tracked' => count($tracked)];
    }
}
