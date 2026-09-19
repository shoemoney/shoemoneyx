<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Strategies\SchemaMigrator;
use App\Desk\Strategies\StrategySchemaValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SchemaMigratorTest extends TestCase
{
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
     * Placeholder for the spec's hard proof ("every v1 example round-trips ...
     * produce byte-identical backtest fills") until phase C's ladder engine
     * lands — Backtester has nothing to run a v2 take_profit.ladder against
     * yet (SchemaMigrator::migrate() refuses schema_version:2 until then).
     * Asserted here in closed form: the percent-of-original ladder computed
     * from a 3-rung fraction-of-remaining v1 ladder sells the same absolute
     * quantities, in order, as the v1 ladder would against a fixed starting
     * position.
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
}
