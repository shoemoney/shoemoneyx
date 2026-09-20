<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Data\ProductStats;
use App\Desk\DeskContext;
use App\Desk\Strategies\JsonRuleEvaluator;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Round-9 review, MINOR: the "between" warning's dedupe key preferred json.plugin_key over
 * json.plugin_version_id (the more specific identity — a plugin_key can span several published
 * versions of the same strategy) and fell back to an empty string '' when both were absent,
 * silently sharing ONE dedupe slot across every identity-less caller. Fixed: plugin_version_id
 * wins when present, and dedupe is skipped entirely (always logs) when neither is set, rather
 * than keying on ''.
 */
class JsonRuleEvaluatorBetweenWarningKeyTest extends TestCase
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

    private function ctxWith(?string $pluginKey, ?int $pluginVersionId): DeskContext
    {
        return new DeskContext(
            ['json' => array_filter(['plugin_key' => $pluginKey, 'plugin_version_id' => $pluginVersionId], fn ($v) => $v !== null)],
            'backtest', false, [], new \DateTimeImmutable('2026-01-01T00:00:00Z', new \DateTimeZone('UTC')),
        );
    }

    private array $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = ['field' => 'spread_bps', 'op' => 'between', 'value' => ['lo' => 1, 'hi' => 1000]];
    }

    #[Test]
    public function plugin_version_id_wins_over_plugin_key_for_the_dedupe_key(): void
    {
        Log::spy();
        $s = $this->stats();

        // Same plugin_key, DIFFERENT plugin_version_id -> must be treated as different strategy
        // identities (a plugin_key can span several published versions) -> logs for each.
        JsonRuleEvaluator::fires($this->rule, $s, null, $this->ctxWith('plugin-a', 1));
        JsonRuleEvaluator::fires($this->rule, $s, null, $this->ctxWith('plugin-a', 2));
        Log::shouldHaveReceived('warning')->twice();
    }

    #[Test]
    public function same_plugin_version_id_dedupes_even_across_different_plugin_keys(): void
    {
        Log::spy();
        $s = $this->stats();

        // Different plugin_key, SAME plugin_version_id -> plugin_version_id must win, so these
        // dedupe together (proves it isn't just "prefer key, then also check version").
        JsonRuleEvaluator::fires($this->rule, $s, null, $this->ctxWith('plugin-a', 1));
        JsonRuleEvaluator::fires($this->rule, $s, null, $this->ctxWith('plugin-b', 1));
        Log::shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function int_plugin_version_id_with_no_plugin_key_still_dedupes(): void
    {
        // Round-10 review, MAJOR: every real producer (BacktestVersionPin, ArenaRunner,
        // RunBacktestTool) sets json.plugin_version_id to a plain int with no plugin_key at
        // all — an arena seat is exactly this shape. A stratKeyFor() that only accepted
        // is_string() never matched this and fell through to "no safe key, always log",
        // spamming a warning on every single evaluation instead of deduping.
        Log::spy();
        $s = $this->stats();
        $ctx = $this->ctxWith(null, 42);

        JsonRuleEvaluator::fires($this->rule, $s, null, $ctx);
        JsonRuleEvaluator::fires($this->rule, $s, null, $ctx);
        JsonRuleEvaluator::fires($this->rule, $s, null, $ctx);

        Log::shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function no_identity_at_all_skips_dedupe_and_logs_every_evaluation(): void
    {
        Log::spy();
        $s = $this->stats();
        $ctx = $this->ctxWith(null, null);

        // Keying a shared dedupe slot on '' would let one identity-less caller's warning silence
        // a completely unrelated identity-less caller's own broken rule. Skip dedupe instead.
        JsonRuleEvaluator::fires($this->rule, $s, null, $ctx);
        JsonRuleEvaluator::fires($this->rule, $s, null, $ctx);
        JsonRuleEvaluator::fires($this->rule, $s, null, $ctx);

        Log::shouldHaveReceived('warning')->times(3);
    }
}
