<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Strategies\SchemaMigrator;
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
}
