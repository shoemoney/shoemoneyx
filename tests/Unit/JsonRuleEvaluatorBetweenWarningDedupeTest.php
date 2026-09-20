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
 * Round-9 review, MINOR: the "between" legacy-value warning was deduped via a static PHP array,
 * which lives in process memory — it resets to empty every time a fresh process starts. That is
 * exactly what desk:risk's everyMinute scheduler does (`php artisan schedule:run` forks a new
 * `php artisan desk:risk` process every tick), so in production this "logs once" promise was a
 * complete no-op: every single scheduled tick got its own fresh static array and re-logged.
 * Backing the dedupe with Cache::add() (ttl 1h) makes it survive across processes, the way the
 * doc's "once per hour" promise requires. Proven here by seeding the cache key directly — the
 * way a PRIOR process's warning would have left it — and confirming THIS (fresh, never-warned-
 * before-in-this-process) evaluation still stays quiet.
 */
class JsonRuleEvaluatorBetweenWarningDedupeTest extends TestCase
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
    public function a_warning_already_recorded_by_a_prior_process_survives_this_processs_fresh_static_state(): void
    {
        Log::spy();
        $s = $this->stats();
        $ctx = $this->ctxAt('2026-01-01T00:00:00Z');
        $expected = ['lo' => 1, 'hi' => 1000];

        // Simulate what a PRIOR `php artisan desk:risk` process already recorded: the exact cache
        // key this rule/strategy/value combination would use, seeded directly — no static array
        // involved at all, exactly like a fresh process that never called between() before would
        // see. Empty strategy key ('') matches the no-plugin-context $ctx used here.
        $key = JsonRuleEvaluator::BETWEEN_WARNING_CACHE_PREFIX.'|spread_bps|'.json_encode($expected);
        Cache::put($key, true, now()->addHour());

        // THIS process's static state (if the old code's array were still in play) has never seen
        // this rule — a static-array-only dedupe would log anyway. The fix must consult the same
        // cache the "prior process" wrote to and stay quiet.
        JsonRuleEvaluator::fires(['field' => 'spread_bps', 'op' => 'between', 'value' => $expected], $s, null, $ctx);

        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function the_warning_expires_and_logs_again_after_the_cache_entry_is_gone(): void
    {
        Log::spy();
        $s = $this->stats();
        $ctx = $this->ctxAt('2026-01-01T00:00:00Z');
        $rule = ['field' => 'spread_bps', 'op' => 'between', 'value' => ['lo' => 1, 'hi' => 1000]];

        JsonRuleEvaluator::fires($rule, $s, null, $ctx);
        Log::shouldHaveReceived('warning')->once();

        // Simulate the cache entry expiring (an hour has passed) by clearing it directly.
        $key = JsonRuleEvaluator::BETWEEN_WARNING_CACHE_PREFIX.'|spread_bps|'.json_encode($rule['value']);
        Cache::forget($key);

        JsonRuleEvaluator::fires($rule, $s, null, $ctx);
        Log::shouldHaveReceived('warning')->twice();
    }
}
