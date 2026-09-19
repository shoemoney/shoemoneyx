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
}
