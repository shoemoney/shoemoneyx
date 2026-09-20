<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Data\ProductStats;
use App\Desk\DeskContext;
use App\Desk\Strategies\JsonRuleEvaluator;
use Illuminate\Support\Facades\Log;
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

    /**
     * Round-6 review, BLOCKER 2: a stored rule whose "between" value is an object (e.g.
     * {"lo":1,"hi":1000}, decoded from JSON as an associative array with keys 0 absent) has
     * count() 2 and used to throw "Undefined array key 0" here. Must fail closed instead, so a
     * pre-existing stored strategy degrades safely rather than aborting risk() (and, pre round-6
     * blocker 1, starving every position after it in the sweep).
     */
    #[Test]
    public function between_fails_closed_instead_of_throwing_when_the_value_is_not_a_list(): void
    {
        $s = $this->stats();
        $ctx = $this->ctxAt('2026-01-01T00:00:00Z');

        $this->assertFalse(JsonRuleEvaluator::fires(['field' => 'spread_bps', 'op' => 'between', 'value' => ['lo' => 1, 'hi' => 1000]], $s, null, $ctx));
        $this->assertFalse(JsonRuleEvaluator::fires(['field' => 'spread_bps', 'op' => 'between', 'value' => [0 => 1, 2 => 1000]], $s, null, $ctx));
    }

    /**
     * Round-7 review, MINOR: the fail-closed path above (round 6) is silent — no log line, no
     * reporter event — so a definition stored before round 6 (when a two-key object still
     * passed the old count()-only check) degrades without ever telling anyone. That's fail-open
     * for a v1 stop rule (OR'ed with the rest) or a v2 `all` group. The first evaluation of a
     * non-list "between" value must log a warning naming the field, so a legacy definition
     * announces itself instead of just quietly never firing again.
     */
    #[Test]
    public function between_logs_a_warning_naming_the_field_when_the_value_is_not_a_list(): void
    {
        Log::spy();
        $s = $this->stats();
        $ctx = $this->ctxAt('2026-01-01T00:00:00Z');

        JsonRuleEvaluator::fires(['field' => 'spread_bps', 'op' => 'between', 'value' => ['lo' => 1, 'hi' => 1000]], $s, null, $ctx);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message) => str_contains($message, 'spread_bps') && str_contains($message, 'between')
        );
    }

    /**
     * Round-8 review, MINOR: docs/STRATEGY_SCHEMA_V2.md says this logs "the first time it is
     * evaluated", but the code logged on EVERY evaluation — one warning line per sweep for as
     * long as the broken definition kept getting loaded, not the one-time announcement the doc
     * promises. Repeated evaluations of the SAME rule for the SAME strategy must log only once.
     *
     * Round-9 review, MINOR: the dedupe now requires a strategy identity to key on (a plugin_key
     * or plugin_version_id) — an identity-less caller skips the dedupe entirely instead of
     * sharing a slot keyed on '' (see JsonRuleEvaluatorBetweenWarningKeyTest), so this test needs
     * one to still exercise the "once per real strategy" behavior it is named for.
     */
    #[Test]
    public function between_logs_only_once_across_repeated_evaluations_of_the_same_rule(): void
    {
        Log::spy();
        $s = $this->stats();
        $ctx = new DeskContext(['json' => ['plugin_version_id' => 'v1']], 'backtest', false, [], new \DateTimeImmutable('2026-01-01T00:00:00Z', new \DateTimeZone('UTC')));
        $rule = ['field' => 'spread_bps', 'op' => 'between', 'value' => ['lo' => 1, 'hi' => 1000]];

        // Three sweeps' worth of evaluations against the same stored (legacy) rule.
        JsonRuleEvaluator::fires($rule, $s, null, $ctx);
        JsonRuleEvaluator::fires($rule, $s, null, $ctx);
        JsonRuleEvaluator::fires($rule, $s, null, $ctx);

        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * The dedupe key includes the strategy key so ONE broken plugin going quiet never silences
     * the warning for a DIFFERENT plugin with its own broken "between" rule on the same field.
     */
    #[Test]
    public function between_logs_again_for_a_different_strategy_key_with_the_same_broken_rule(): void
    {
        Log::spy();
        $s = $this->stats();
        $rule = ['field' => 'spread_bps', 'op' => 'between', 'value' => ['lo' => 1, 'hi' => 1000]];
        $ctxA = new DeskContext(['json' => ['plugin_key' => 'plugin-a']], 'backtest', false, [], new \DateTimeImmutable('2026-01-01T00:00:00Z', new \DateTimeZone('UTC')));
        $ctxB = new DeskContext(['json' => ['plugin_key' => 'plugin-b']], 'backtest', false, [], new \DateTimeImmutable('2026-01-01T00:00:00Z', new \DateTimeZone('UTC')));

        JsonRuleEvaluator::fires($rule, $s, null, $ctxA);
        JsonRuleEvaluator::fires($rule, $s, null, $ctxA);
        JsonRuleEvaluator::fires($rule, $s, null, $ctxB);

        Log::shouldHaveReceived('warning')->twice();
    }

    /** Sanity control: a healthy list value must not log anything, or the assertion above proves nothing. */
    #[Test]
    public function between_logs_nothing_for_a_healthy_list_value(): void
    {
        Log::spy();
        $s = $this->stats();
        $ctx = $this->ctxAt('2026-01-01T00:00:00Z');

        JsonRuleEvaluator::fires(['field' => 'spread_bps', 'op' => 'between', 'value' => [5, 10]], $s, null, $ctx);

        Log::shouldNotHaveReceived('warning');
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
