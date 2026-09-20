<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Data\ProductStats;
use App\Desk\DeskContext;
use App\Desk\Strategies\JsonRuleEvaluator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Round-10 review, NIT: the "between" dedupe used to be a static PHP array, which could never
 * throw. Backing it with Cache::add() (round 9) means a cache-store outage now turns evaluating a
 * broken legacy "between" definition into an uncaught exception — caught by runRiskSweep()'s
 * outer handler, reporting "risk evaluation failed" and an 'error' action for the WHOLE position
 * instead of just skipping this one rule's dedupe. Asserts a throwing cache store degrades to
 * "just log it" instead of propagating.
 */
class JsonRuleEvaluatorBetweenCacheOutageTest extends TestCase
{
    private function stats(): ProductStats
    {
        return new ProductStats(
            productId: 'BTC-USD',
            price: 100.0,
            bestBid: 99.9,
            bestAsk: 100.1,
            ageHours: 100.0,
            volumeM5Usd: 1000.0,
            volumeH1Usd: 12000.0,
            volumeH6Usd: 60000.0,
            volumeH24Usd: 240000.0,
            volumePrevH24Usd: 200000.0,
            priceChangeM5Pct: 0.1,
            priceChangeH1Pct: 0.5,
            priceChangeH6Pct: 1.0,
            priceChangeH24Pct: 2.0,
            buysH1: 60,
            sellsH1: 40,
            buysM5: 6,
            sellsM5: 4,
            buyVolumeH1Usd: 7000.0,
            sellVolumeH1Usd: 5000.0,
            spreadBps: 10.0,
            bookDepthUsd: 50000.0,
            candlesH1Count: 100,
            extra: ['indicators' => ['rsi14' => 30.0]],
        );
    }

    #[Test]
    public function a_cache_store_outage_degrades_to_logging_instead_of_throwing(): void
    {
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('cache store unreachable'));
        Log::spy();

        $s = $this->stats();
        $ctx = new DeskContext(['json' => ['plugin_version_id' => 1]], 'backtest', false, [], new \DateTimeImmutable('2026-01-01T00:00:00Z', new \DateTimeZone('UTC')));
        $rule = ['field' => 'spread_bps', 'op' => 'between', 'value' => ['lo' => 1, 'hi' => 1000]];

        // Must not throw — runRiskSweep() has no idea this dedupe even exists internally, and a
        // thrown exception here would be caught as "risk evaluation failed" for the WHOLE position.
        $result = JsonRuleEvaluator::fires($rule, $s, null, $ctx);

        $this->assertFalse($result, 'a non-list "between" value never fires, cache outage or not');
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message) => str_contains($message, 'spread_bps') && str_contains($message, 'between')
        );
    }
}
