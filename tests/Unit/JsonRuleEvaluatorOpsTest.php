<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Data\ProductStats;
use App\Desk\DeskContext;
use App\Desk\Strategies\JsonRuleEvaluator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Covers the ops added for the formal schema's setup/trigger sections: between, in, not_in, time.* fields. */
class JsonRuleEvaluatorOpsTest extends TestCase
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

    private function ctxAt(string $utc): DeskContext
    {
        return new DeskContext([], 'backtest', false, [], new \DateTimeImmutable($utc, new \DateTimeZone('UTC')));
    }

    #[Test]
    public function between_fires_inclusive(): void
    {
        $s = $this->stats();
        $ctx = $this->ctxAt('2026-01-01T00:00:00Z');

        $this->assertTrue(JsonRuleEvaluator::fires(['field' => 'spread_bps', 'op' => 'between', 'value' => [5, 10]], $s, null, $ctx));
        $this->assertTrue(JsonRuleEvaluator::fires(['field' => 'spread_bps', 'op' => 'between', 'value' => [10, 20]], $s, null, $ctx));
        $this->assertFalse(JsonRuleEvaluator::fires(['field' => 'spread_bps', 'op' => 'between', 'value' => [11, 20]], $s, null, $ctx));
        $this->assertFalse(JsonRuleEvaluator::fires(['field' => 'spread_bps', 'op' => 'between', 'value' => [1]], $s, null, $ctx));
    }

    #[Test]
    public function in_and_not_in_test_membership(): void
    {
        $s = $this->stats();
        $ctx = $this->ctxAt('2026-01-01T00:00:00Z');

        $this->assertTrue(JsonRuleEvaluator::fires(['field' => 'product_id', 'op' => 'in', 'value' => ['BTC-USD', 'ETH-USD']], $s, null, $ctx));
        $this->assertFalse(JsonRuleEvaluator::fires(['field' => 'product_id', 'op' => 'in', 'value' => ['ETH-USD']], $s, null, $ctx));
        $this->assertTrue(JsonRuleEvaluator::fires(['field' => 'product_id', 'op' => 'not_in', 'value' => ['ETH-USD']], $s, null, $ctx));
        $this->assertFalse(JsonRuleEvaluator::fires(['field' => 'product_id', 'op' => 'not_in', 'value' => ['BTC-USD']], $s, null, $ctx));
        $this->assertFalse(JsonRuleEvaluator::fires(['field' => 'product_id', 'op' => 'not_in', 'value' => []], $s, null, $ctx));
    }

    #[Test]
    public function time_fields_read_the_context_clock(): void
    {
        $s = $this->stats();

        $this->assertSame(14, JsonRuleEvaluator::value('time.hour_utc', $s, null, $this->ctxAt('2026-03-05T14:30:00Z')));
        $this->assertTrue(JsonRuleEvaluator::fires(['field' => 'time.hour_utc', 'op' => 'between', 'value' => [12, 20]], $s, null, $this->ctxAt('2026-03-05T14:30:00Z')));
        $this->assertFalse(JsonRuleEvaluator::fires(['field' => 'time.hour_utc', 'op' => 'between', 'value' => [12, 20]], $s, null, $this->ctxAt('2026-03-05T02:00:00Z')));
        // 2026-03-05 is a Thursday: weekday 4 (0 = Sunday).
        $this->assertSame(4, JsonRuleEvaluator::value('time.weekday', $s, null, $this->ctxAt('2026-03-05T00:00:00Z')));
    }
}
