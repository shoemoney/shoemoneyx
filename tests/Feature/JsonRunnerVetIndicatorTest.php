<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Data\Bank;
use App\Desk\Data\Candidate;
use App\Desk\Data\ProductStats;
use App\Desk\DeskContext;
use App\Desk\Strategies\JsonPluginStrategy;
use App\Models\StrategyPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * entry.confirm's fail-closed vet path for `ind.*` fields (JsonPluginStrategy::vet(),
 * docs/STRATEGY_SCHEMA_V2.md phase A): an unmeasurable indicator rejects the candidate,
 * pinned against the opposite v1 behaviour (a stats field on missing data is skipped, since
 * scan already excluded unmeasurable rows) so a future refactor cannot quietly make both fail
 * open or both fail closed.
 */
class JsonRunnerVetIndicatorTest extends TestCase
{
    use RefreshDatabase;

    private function plugin(string $key, array $confirm): StrategyPlugin
    {
        return StrategyPlugin::create([
            'key' => $key,
            'name' => 'Vet indicator test',
            'definition' => [
                'schema_version' => 1,
                'key' => $key,
                'meta' => ['name' => 'Vet indicator test', 'timeframe' => '1h'],
                'entry' => ['side' => 'long', 'confirm' => $confirm],
            ],
        ]);
    }

    private function candidate(): Candidate
    {
        $stats = ProductStats::fromArray([
            'product_id' => 'AAA-USD', 'price' => 10.0, 'best_bid' => 9.99, 'best_ask' => 10.01, 'age_hours' => 500,
            'volume_m5_usd' => 5000, 'volume_h1_usd' => 250000, 'volume_h6_usd' => 400000,
            'volume_h24_usd' => 1200000, 'volume_prev_h24_usd' => 800000,
            'price_change_m5_pct' => 0.1, 'price_change_h1_pct' => 1.0, 'price_change_h6_pct' => 2.0, 'price_change_h24_pct' => 4.0,
            'buys_h1' => 70, 'sells_h1' => 30, 'buys_m5' => 6, 'sells_m5' => 4,
            'buy_volume_h1_usd' => 70000, 'sell_volume_h1_usd' => 30000,
            'spread_bps' => 5, 'book_depth_usd' => 500000, 'bid_depth_usd' => 500000, 'ask_depth_usd' => 500000,
            'quote_age_sec' => 1, 'tape_sample_size' => 100, 'candles_h1_count' => 100,
        ]);

        return new Candidate(stats: $stats, score: 1.0, rankReason: 'test', edgeProbability: 0.55, payoffRatio: 1.5);
    }

    /** @param array<string, array<int, array{start:int,open:float,high:float,low:float,close:float,volume:float}>> $barsByTf */
    private function ctx(string $key, array $barsByTf): DeskContext
    {
        $provider = function (string $pid, string $tf, int $from, ?int $to) use ($barsByTf): array {
            foreach ($barsByTf as $k => $bars) {
                if (strcasecmp($k, $tf) === 0) {
                    return $bars;
                }
            }

            return [];
        };

        return new DeskContext(
            ['json' => ['plugin_key' => $key]],
            'backtest',
            false,
            [],
            new \DateTimeImmutable('2026-09-11 12:00:00', new \DateTimeZone('UTC')),
            true,
            $provider,
        );
    }

    public function test_an_unmeasurable_ind_confirm_rule_rejects_the_entry(): void
    {
        $this->plugin('vet-ind-1', [
            ['field' => 'ind.rsi(14)', 'op' => '<', 'value' => 30],
        ]);
        // Two hourly bars is nowhere near enough history for rsi(14) -- it resolves null.
        $bars = [
            ['start' => strtotime('2026-09-11 10:00:00'), 'open' => 10, 'high' => 10, 'low' => 10, 'close' => 10, 'volume' => 1],
            ['start' => strtotime('2026-09-11 11:00:00'), 'open' => 10, 'high' => 10, 'low' => 10, 'close' => 10, 'volume' => 1],
        ];
        $ctx = $this->ctx('vet-ind-1', ['1H' => $bars]);

        $verdict = (new JsonPluginStrategy)->vet($this->candidate(), new Bank(1000, 0, 0.2, 0, 0), $ctx);

        $this->assertFalse($verdict->passed());
        $this->assertSame('json.ind.rsi(14)', $verdict->failedCheck);
    }

    public function test_a_v1_stats_field_on_missing_data_is_skipped_not_rejected(): void
    {
        $this->plugin('vet-ind-2', [
            ['field' => 'extra.indicators.rsi14', 'op' => '<', 'value' => 30],
        ]);
        $ctx = $this->ctx('vet-ind-2', []);

        $verdict = (new JsonPluginStrategy)->vet($this->candidate(), new Bank(1000, 0, 0.2, 0, 0), $ctx);

        $this->assertTrue($verdict->passed());
    }
}
