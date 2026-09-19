<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Backtester;
use App\Desk\Strategies\SchemaMigrator;
use App\Desk\Strategies\StrategySchemaValidator;
use App\Models\Candle;
use App\Models\Product;
use App\Models\StrategyPlugin;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SchemaMigratorTest extends TestCase
{
    use RefreshDatabase;

    private function legacyDefinition(): array
    {
        return [
            'key' => 'rsi-dip',
            'name' => 'RSI Dip',
            'description' => 'Buys dips.',
            'version' => 1,
            'scan' => [
                'max_candidates' => 5,
                'filters' => [['field' => 'indicators.rsi14', 'op' => '<', 'value' => 35]],
            ],
            'vet' => ['rules' => [['field' => 'spread_bps', 'op' => '<=', 'value' => 15, 'reason' => 'wide']]],
            'size' => ['kelly_fraction' => 0.25, 'max_pct_book' => 6.0],
            'risk' => ['rules' => [['field' => 'volume_ratio_6h', 'op' => '<', 'value' => 0.2, 'action' => 'close']]],
        ];
    }

    #[Test]
    public function legacy_flat_shape_maps_forward_to_the_canonical_sections(): void
    {
        $out = SchemaMigrator::migrate($this->legacyDefinition());

        $this->assertSame(1, $out['schema_version']);
        $this->assertSame('RSI Dip', $out['meta']['name']);
        $this->assertSame('Buys dips.', $out['meta']['description']);
        $this->assertSame(5, $out['trigger']['max_candidates']);
        $this->assertSame('indicators.rsi14', $out['trigger']['rules'][0]['field']);
        $this->assertSame('spread_bps', $out['entry']['confirm'][0]['field']);
        $this->assertSame(0.25, $out['entry']['sizing']['kelly_fraction']);
        $this->assertSame('volume_ratio_6h', $out['exit']['stop']['rules'][0]['field']);
        $this->assertArrayNotHasKey('scan', $out);
        $this->assertArrayNotHasKey('vet', $out);
    }

    #[Test]
    public function a_canonical_definition_passes_through_unchanged(): void
    {
        $canonical = ['schema_version' => 1, 'key' => 'k', 'meta' => ['name' => 'K'], 'entry' => []];

        $this->assertSame($canonical, SchemaMigrator::migrate($canonical));
    }

    #[Test]
    public function migrate_passes_a_canonical_v2_definition_through_unchanged(): void
    {
        $v2 = ['schema_version' => 2, 'key' => 'k', 'meta' => ['name' => 'K'], 'entry' => ['when' => ['a']]];

        $this->assertSame($v2, SchemaMigrator::migrate($v2));
    }

    #[Test]
    public function validate_for_save_runs_the_v2_validator_for_a_schema_version_2_definition(): void
    {
        $v2 = json_decode(file_get_contents(base_path('resources/strategies/examples/smx-pi-take-profit-v2.json')), true);

        $result = SchemaMigrator::validateForSave($v2);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    #[Test]
    public function to_legacy_view_projects_canonical_definitions_back_to_the_flat_shape(): void
    {
        $canonical = SchemaMigrator::migrate($this->legacyDefinition());
        $view = SchemaMigrator::toLegacyView($canonical);

        $this->assertSame(5, $view['scan']['max_candidates']);
        $this->assertSame('indicators.rsi14', $view['scan']['filters'][0]['field']);
        $this->assertSame('spread_bps', $view['vet']['rules'][0]['field']);
        $this->assertSame(0.25, $view['size']['kelly_fraction']);
        $this->assertSame('volume_ratio_6h', $view['risk']['rules'][0]['field']);
    }

    #[Test]
    public function to_legacy_view_is_a_no_op_on_an_already_legacy_definition(): void
    {
        $legacy = $this->legacyDefinition();

        $this->assertSame($legacy, SchemaMigrator::toLegacyView($legacy));
    }

    #[Test]
    public function v1_to_v2_migrates_the_shipped_mean_reversion_example_to_a_valid_v2_definition(): void
    {
        $v1 = json_decode(file_get_contents(base_path('resources/strategies/examples/mean-reversion.json')), true);

        $v2 = SchemaMigrator::v1ToV2($v1);

        $this->assertSame(2, $v2['schema_version']);
        $this->assertSame(['setup', 'trigger'], $v2['entry']['when']);
        $this->assertSame('indicators.rsi14', $v2['signals']['trigger']['all'][0]['field']);
        $this->assertSame(5, $v2['entry']['max_candidates']);
        $this->assertSame('kelly', $v2['entry']['size']['mode']);
        $this->assertSame(0.25, $v2['entry']['size']['fraction']);
        $this->assertSame('volume_ratio_6h', $v2['stop']['rules'][0]['field']);
        $this->assertSame(48, $v2['stop']['time_hours']);
        $this->assertArrayNotHasKey('take_profit', $v2); // no partials/trailing to migrate

        $result = StrategySchemaValidator::validate($v2);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    #[Test]
    public function v1_to_v2_converts_partials_to_a_percent_of_original_ladder_exactly(): void
    {
        $v1 = [
            'schema_version' => 1,
            'key' => 'partials-fixture',
            'meta' => ['name' => 'Partials Fixture'],
            'entry' => ['side' => 'long'],
            'management' => [
                'partials' => [
                    ['pct' => 1, 'fraction' => 0.5],
                    ['pct' => 2, 'fraction' => 0.5],
                    ['pct' => 3, 'fraction' => 1.0],
                ],
            ],
        ];

        $ladder = SchemaMigrator::v1ToV2($v1)['take_profit'];

        // f_i × Π(1 − f_j, j<i) × 100
        $this->assertSame([1, 2, 3], array_column($ladder['ladder'], 'at_pct'));
        $this->assertEqualsWithDelta([50.0, 25.0, 25.0], array_column($ladder['ladder'], 'sell_pct_of_original'), 0.0001);
        $this->assertFalse($ladder['reset_on_add']);
    }

    #[Test]
    public function v1_to_v2_carries_the_trailing_stop_into_the_runner_ttp(): void
    {
        $v1 = [
            'schema_version' => 1,
            'key' => 'trailing-fixture',
            'meta' => ['name' => 'Trailing Fixture'],
            'entry' => ['side' => 'long'],
            'management' => ['trailing' => ['activate_pct' => 3, 'trail_pct' => 1]],
        ];

        $takeProfit = SchemaMigrator::v1ToV2($v1)['take_profit'];

        $this->assertSame(3, $takeProfit['runner']['ttp']['activate_pct']);
        $this->assertSame(1, $takeProfit['runner']['ttp']['giveback_pct']);
    }

    #[Test]
    public function v2_to_v1_view_projects_signals_and_stop_back_to_the_legacy_shape(): void
    {
        $v1 = json_decode(file_get_contents(base_path('resources/strategies/examples/mean-reversion.json')), true);
        $v2 = SchemaMigrator::v1ToV2($v1);

        $view = SchemaMigrator::v2ToV1View($v2);

        $this->assertSame(1, $view['schema_version']);
        $this->assertSame(5, $view['trigger']['max_candidates']);
        $this->assertSame('indicators.rsi14', $view['trigger']['rules'][0]['field']);
        $this->assertSame(0.25, $view['entry']['sizing']['kelly_fraction']);
        $this->assertSame('volume_ratio_6h', $view['exit']['stop']['rules'][0]['field']);
        $this->assertSame(48, $view['exit']['time_stop']['hours']);
    }

    #[Test]
    public function to_legacy_view_handles_v2_definitions_for_the_exporters(): void
    {
        $v1 = json_decode(file_get_contents(base_path('resources/strategies/examples/mean-reversion.json')), true);
        $v2 = SchemaMigrator::v1ToV2($v1);

        $legacy = SchemaMigrator::toLegacyView($v2);

        $this->assertSame(5, $legacy['scan']['max_candidates']);
        $this->assertSame('spread_bps', $legacy['vet']['rules'][0]['field']);
        $this->assertSame(0.25, $legacy['size']['kelly_fraction']);
        $this->assertSame('volume_ratio_6h', $legacy['risk']['rules'][0]['field']);
    }

    #[Test]
    public function v1_to_v2_carries_base_and_suggest_through_unchanged(): void
    {
        $v1 = json_decode(file_get_contents(base_path('resources/strategies/examples/mean-reversion.json')), true);

        $v2 = SchemaMigrator::v1ToV2($v1);

        $this->assertSame($v1['base'], $v2['base']);
        $this->assertSame($v1['suggest'], $v2['suggest']);
    }

    #[Test]
    public function v2_to_v1_view_carries_base_and_suggest_back_through(): void
    {
        $v1 = json_decode(file_get_contents(base_path('resources/strategies/examples/mean-reversion.json')), true);
        $v2 = SchemaMigrator::v1ToV2($v1);

        $view = SchemaMigrator::v2ToV1View($v2);

        $this->assertSame($v1['base'], $view['base']);
        $this->assertSame($v1['suggest'], $view['suggest']);
    }

    #[Test]
    public function v1_to_v2_round_trips_every_shipped_v1_example_to_a_valid_v2_definition(): void
    {
        $checked = 0;
        foreach (glob(base_path('resources/strategies/examples/*.json')) as $file) {
            $def = json_decode(file_get_contents($file), true);
            if (($def['schema_version'] ?? 1) !== 1) {
                continue;
            }

            $v2 = SchemaMigrator::v1ToV2($def);
            $result = StrategySchemaValidator::validate($v2);

            $this->assertTrue($result['valid'], basename($file).': '.json_encode($result['errors']));
            foreach (['base', 'suggest'] as $k) {
                if (array_key_exists($k, $def)) {
                    $this->assertSame($def[$k], $v2[$k], basename($file).": {$k} did not survive v1ToV2()");
                }
            }
            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'no v1 examples found to round-trip');
    }

    #[Test]
    public function v1_to_v2_defaults_entry_size_to_half_kelly_when_v1_declared_no_sizing_and_still_validates(): void
    {
        $v1 = [
            'schema_version' => 1,
            'key' => 'no-sizing',
            'meta' => ['name' => 'No Sizing'],
            'entry' => ['side' => 'long'],
            'trigger' => ['rules' => [['field' => 'price', 'op' => '>', 'value' => 1]]],
        ];

        $v2 = SchemaMigrator::v1ToV2($v1);

        $this->assertSame(['mode' => 'kelly', 'fraction' => 0.5], $v2['entry']['size']);
        $this->assertTrue(StrategySchemaValidator::validate($v2)['valid'], json_encode(StrategySchemaValidator::validate($v2)['errors']));
    }

    #[Test]
    public function v1_to_v2_defaults_fraction_when_v1_sizing_declared_only_max_pct_book(): void
    {
        $v1 = [
            'schema_version' => 1,
            'key' => 'max-pct-book-only',
            'meta' => ['name' => 'Max Pct Book Only'],
            'entry' => ['side' => 'long', 'sizing' => ['max_pct_book' => 6]],
            'trigger' => ['rules' => [['field' => 'price', 'op' => '>', 'value' => 1]]],
        ];

        $v2 = SchemaMigrator::v1ToV2($v1);

        $this->assertSame(['mode' => 'kelly', 'fraction' => 0.5, 'max_pct_book' => 6], $v2['entry']['size']);
        $this->assertTrue(StrategySchemaValidator::validate($v2)['valid'], json_encode(StrategySchemaValidator::validate($v2)['errors']));
    }

    #[Test]
    public function v1_to_v2_synthesises_entry_when_for_a_v1_definition_with_no_setup_or_trigger(): void
    {
        $v1 = [
            'schema_version' => 1,
            'key' => 'kk',
            'meta' => ['name' => 'K'],
            'entry' => ['side' => 'long'],
            'management' => ['partials' => [['pct' => 5, 'fraction' => 1]]],
        ];

        $v2 = SchemaMigrator::v1ToV2($v1);

        $this->assertSame(['setup'], $v2['entry']['when']);
        $this->assertSame(['all' => []], $v2['signals']['setup']);
        $this->assertTrue(StrategySchemaValidator::validate($v2)['valid'], json_encode(StrategySchemaValidator::validate($v2)['errors']));
    }

    #[Test]
    public function v1_to_v2_drops_a_partials_rung_that_would_round_to_a_zero_percent_ladder_step(): void
    {
        $v1 = [
            'schema_version' => 1,
            'key' => 'full-fraction-then-more',
            'meta' => ['name' => 'Full Fraction Then More'],
            'entry' => ['side' => 'long'],
            'management' => [
                'partials' => [
                    ['pct' => 1, 'fraction' => 1.0],
                    ['pct' => 2, 'fraction' => 0.5],
                ],
            ],
        ];

        $ladder = SchemaMigrator::v1ToV2($v1)['take_profit']['ladder'];

        $this->assertCount(1, $ladder);
        $this->assertSame(1, $ladder[0]['at_pct']);
        $this->assertSame(100.0, $ladder[0]['sell_pct_of_original']);
    }

    #[Test]
    public function v2_to_v1_view_drops_any_rules_instead_of_and_ing_them_into_scan_filters(): void
    {
        $v2 = [
            'schema_version' => 2,
            'key' => 'any-only',
            'meta' => ['name' => 'Any Only'],
            'signals' => [
                'trigger' => ['any' => [
                    ['field' => 'spread_bps', 'op' => '<', 'value' => 10],
                    ['field' => 'spread_bps', 'op' => '>', 'value' => 90],
                ]],
            ],
            'entry' => ['side' => 'long', 'when' => ['trigger']],
        ];

        $view = SchemaMigrator::v2ToV1View($v2);
        $legacy = SchemaMigrator::toLegacyView($v2);

        $this->assertSame([], $view['trigger']['rules']);
        $this->assertSame([], $legacy['scan']['filters']);
    }

    /**
     * Closed-form companion to the real round trip below: the percent-of-original ladder
     * computed from a 3-rung fraction-of-remaining v1 ladder sells the same absolute
     * quantities, in order, as the v1 ladder would against a fixed starting position.
     */
    #[Test]
    public function the_percent_of_original_ladder_implies_the_same_absolute_fills_as_the_v1_fraction_ladder(): void
    {
        $startingQty = 100.0;
        $partials = [
            ['pct' => 1, 'fraction' => 0.5],
            ['pct' => 2, 'fraction' => 0.5],
            ['pct' => 3, 'fraction' => 1.0],
        ];

        // v1: each rung sells `fraction` of whatever remains.
        $remaining = $startingQty;
        $v1Fills = [];
        foreach ($partials as $rung) {
            $sold = $remaining * $rung['fraction'];
            $v1Fills[] = $sold;
            $remaining -= $sold;
        }

        // v2: each rung sells `sell_pct_of_original` percent of the starting quantity.
        $ladder = SchemaMigrator::v1ToV2(['schema_version' => 1, 'key' => 'k', 'meta' => ['name' => 'K'], 'entry' => ['side' => 'long'], 'management' => ['partials' => $partials]])['take_profit']['ladder'];
        $v2Fills = array_map(fn ($rung) => $startingQty * $rung['sell_pct_of_original'] / 100, $ladder);

        $this->assertEqualsWithDelta($v1Fills, $v2Fills, 0.0001);
        $this->assertEqualsWithDelta([50.0, 25.0, 25.0], $v2Fills, 0.0001);
    }

    /** @return array<int, float> [product volume baseline, close price at the end of the tape] */
    private function seedRsiDipTape(string $productId, Carbon $from): void
    {
        $ts = $from->copy();
        $price = 100.0;
        // 24 warmup bars: mild oscillation, enough candles for the base vet chain's own
        // min_candles_h1 gate and to clear Indicators::bundle's 30-bar minimum alongside the
        // decline below (extra.indicators / indicators.rsi14 are both null under 30 bars).
        for ($i = 0; $i < 24; $i++) {
            $ts->addHour();
            $open = $price;
            $price *= 1 + ($i % 2 === 0 ? 0.002 : -0.002);
            Candle::create(['product_id' => $productId, 'timeframe' => '1H', 'candle_start' => $ts->copy(), 'open' => $open, 'high' => max($open, $price), 'low' => min($open, $price), 'close' => $price, 'volume' => 200]);
        }
        // 15 straight-down bars: a sustained decline drives RSI(14) under the trigger's 35
        // threshold on its own, independent of the exact formula's rounding.
        for ($i = 0; $i < 15; $i++) {
            $ts->addHour();
            $open = $price;
            $price *= 0.985;
            Candle::create(['product_id' => $productId, 'timeframe' => '1H', 'candle_start' => $ts->copy(), 'open' => $open, 'high' => $open, 'low' => $price, 'close' => $price, 'volume' => 200]);
        }
        // The trigger bar: continues the decline (RSI stays oversold) on a volume spike
        // (volume_surge_h1 > 1.5), timestamped inside setup's time.hour_utc [12, 20] window —
        // 24 + 15 + 1 = 40 hours past a midnight-UTC $from lands on hour 16.
        $ts->addHour();
        $open = $price;
        $price *= 0.99;
        Candle::create(['product_id' => $productId, 'timeframe' => '1H', 'candle_start' => $ts->copy(), 'open' => $open, 'high' => $open, 'low' => $price, 'close' => $price, 'volume' => 3000]);

        // A few flat bars after the fill: no v1 exit condition in this example (no partials,
        // no adds, no trailing, volume_ratio_6h stays well over 0.2, 48h time stop never
        // reached) fires before the window ends, so both runs close identically at
        // 'end_of_test' — proving the migration round-trips the ENTRY path faithfully, not
        // just a hand-picked exit.
        for ($i = 0; $i < 3; $i++) {
            $ts->addHour();
            $open = $price;
            Candle::create(['product_id' => $productId, 'timeframe' => '1H', 'candle_start' => $ts->copy(), 'open' => $open, 'high' => $open, 'low' => $open, 'close' => $price, 'volume' => 200]);
        }
    }

    /**
     * The spec's own hard proof (docs/STRATEGY_SCHEMA_V2.md, "Migration v1 -> v2"): the shipped
     * v1 example round-trips through SchemaMigrator::v1ToV2() to produce identical Backtester
     * fills. Run twice on two products carrying the exact same candle tape — once on the raw
     * v1 definition, once on its v1ToV2() conversion — with sizing params overridden to match
     * what the v1 definition's own (v1-runtime-inert) `entry.sizing` declares, since v1ToV2
     * carries `kelly_fraction`/`max_pct_book` into a v2 sizing object that actually reads them.
     */
    #[Test]
    public function every_v1_example_round_trips_through_the_migrator_to_identical_backtest_fills(): void
    {
        config(['cache.default' => 'array']);
        Product::create(['product_id' => 'RT-V1', 'base_currency' => 'RT1', 'quote_currency' => 'USD']);
        Product::create(['product_id' => 'RT-V2', 'base_currency' => 'RT2', 'quote_currency' => 'USD']);

        $v1 = json_decode(file_get_contents(base_path('resources/strategies/examples/mean-reversion.json')), true);
        $v1['key'] = 'mean-reversion-roundtrip-v1';
        StrategyPlugin::create(['key' => $v1['key'], 'name' => $v1['meta']['name'], 'definition' => $v1]);

        $v2 = SchemaMigrator::v1ToV2(json_decode(file_get_contents(base_path('resources/strategies/examples/mean-reversion.json')), true));
        $v2['key'] = 'mean-reversion-roundtrip-v2';
        $this->assertTrue(StrategySchemaValidator::validate($v2)['valid'], json_encode(StrategySchemaValidator::validate($v2)['errors']));
        StrategyPlugin::create(['key' => $v2['key'], 'name' => $v2['meta']['name'], 'definition' => $v2]);

        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        $this->seedRsiDipTape('RT-V1', $from);
        $this->seedRsiDipTape('RT-V2', $from);
        $to = $from->copy()->addHours(24 + 15 + 1 + 3 + 1);

        $overrides = [
            'vet.max_pct_of_volume_24h' => 100.0,
            'size.kelly_fraction' => 0.25, 'size.kelly_cap_pct' => 0.06,
            'fees.taker_rate' => 0.0, 'fees.maker_rate' => 0.0, 'fees.funding_hourly_pct' => 0.0,
            'fees.per_contract_usd' => 0.0, 'fees.contract_usd' => 0.0,
            'paper.slippage_bps' => 0.0,
        ];

        $bt1 = app(Backtester::class)->run('json', ['RT-V1'], $from, $to, 100_000.0, ['json.plugin_key' => $v1['key']] + $overrides);
        $bt2 = app(Backtester::class)->run('json', ['RT-V2'], $from, $to, 100_000.0, ['json.plugin_key' => $v2['key']] + $overrides);

        $this->assertSame('done', $bt1->status);
        $this->assertSame('done', $bt2->status);
        $this->assertNotEmpty($bt1->trades, 'the tape must actually produce a fill or this test proves nothing');
        $this->assertCount(count($bt1->trades), $bt2->trades);

        $strip = fn (array $trade) => collect($trade)->except('product')->all();
        $this->assertEquals(array_map($strip, $bt1->trades), array_map($strip, $bt2->trades));
        $this->assertEqualsWithDelta((float) $bt1->ending_equity, (float) $bt2->ending_equity, 0.01);
    }
}
