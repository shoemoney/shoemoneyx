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
 * Round-10 review, MINOR: forgetLoggedBetweenWarnings() called Cache::flush(), wiping the WHOLE
 * configured cache store rather than the dedupe keys its name promises. tests/TestCase.php calls
 * it in setUp() for all 900+ tests — safe today only because phpunit.xml pins CACHE_STORE=array;
 * any run whose cache resolves to the shared KeyDB would flush it every single test. Asserts an
 * unrelated cache key survives the call, while the method still does its own actual job (the
 * dedupe key it owns really is forgotten, so the warning logs again afterwards).
 */
class JsonRuleEvaluatorForgetLoggedBetweenWarningsTest extends TestCase
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
    public function it_forgets_only_its_own_dedupe_keys_never_the_whole_cache_store(): void
    {
        // A cache key completely unrelated to this dedupe — the kind of thing a shared KeyDB
        // store would be holding for some other subsystem entirely.
        Cache::put('some_unrelated_app_key', 'do_not_touch', now()->addHour());

        Log::spy();
        $s = $this->stats();
        $ctx = new DeskContext(['json' => ['plugin_version_id' => 1]], 'backtest', false, [], new \DateTimeImmutable('2026-01-01T00:00:00Z', new \DateTimeZone('UTC')));
        $rule = ['field' => 'spread_bps', 'op' => 'between', 'value' => ['lo' => 1, 'hi' => 1000]];

        // Records one dedupe key and logs once.
        JsonRuleEvaluator::fires($rule, $s, null, $ctx);
        Log::shouldHaveReceived('warning')->once();

        $dedupeKey = JsonRuleEvaluator::BETWEEN_WARNING_CACHE_PREFIX.'1|spread_bps|'.json_encode($rule['value']);
        $this->assertTrue(Cache::has($dedupeKey), 'sanity: the dedupe key must actually be set before we forget it');

        JsonRuleEvaluator::forgetLoggedBetweenWarnings();

        $this->assertSame(
            'do_not_touch',
            Cache::get('some_unrelated_app_key'),
            'forgetLoggedBetweenWarnings() must never touch a cache key it does not own'
        );
        $this->assertFalse(Cache::has($dedupeKey), 'it must still actually forget ITS OWN dedupe key');

        // Proves the forget really took effect on the real dedupe path, not just on the raw key.
        JsonRuleEvaluator::fires($rule, $s, null, $ctx);
        Log::shouldHaveReceived('warning')->twice();
    }
}
