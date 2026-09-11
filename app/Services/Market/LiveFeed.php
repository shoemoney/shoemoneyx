<?php

declare(strict_types=1);

namespace App\Services\Market;

use Illuminate\Support\Facades\Redis;

/**
 * Reads what feeder/feed.mjs writes into Redis from the Coinbase websocket.
 * Every method degrades to null when the feeder is down so callers fall back to REST.
 */
class LiveFeed
{
    public const STALE_SECONDS = 60;

    public function alive(): bool
    {
        try {
            $t = Redis::get('ws:alive');

            return $t !== null && (time() - (int) $t) < self::STALE_SECONDS;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{price:float,bid:?float,ask:?float,vol24:?float,chg24:?float,ts:int}|null */
    public function quote(string $productId): ?array
    {
        try {
            $h = Redis::hgetall('ws:price:'.$productId);
        } catch (\Throwable) {
            return null;
        }
        if (! $h || empty($h['price']) || (time() - (int) ($h['ts'] ?? 0)) > self::STALE_SECONDS) {
            return null;
        }

        return [
            'price' => (float) $h['price'],
            'bid' => $h['bid'] !== '' ? (float) $h['bid'] : null,
            'ask' => $h['ask'] !== '' ? (float) $h['ask'] : null,
            'vol24' => $h['vol24'] !== '' ? (float) $h['vol24'] : null,
            'chg24' => $h['chg24'] !== '' ? (float) $h['chg24'] : null,
            'ts' => (int) $h['ts'],
        ];
    }

    /**
     * Recent trades newest-first: [{t, p, s, side}] — up to 600 prints, only what the
     * feeder has seen since it connected (plus Coinbase's ~100-trade snapshot).
     *
     * @return array<int, array{time:int,price:float,size:float,side:string}>|null
     */
    public function tape(string $productId, int $limit = 600): ?array
    {
        try {
            $rows = Redis::lrange('ws:tape:'.$productId, 0, $limit - 1);
        } catch (\Throwable) {
            return null;
        }
        if (! $rows) {
            return null;
        }
        $out = [];
        foreach ($rows as $r) {
            $j = json_decode((string) $r, true);
            if (! $j) {
                continue;
            }
            $out[] = ['time' => (int) $j['t'], 'price' => (float) $j['p'], 'size' => (float) $j['s'], 'side' => strtolower((string) $j['side'])];
        }

        return $out;
    }
}
