<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Data\ProductStats;
use App\Desk\DeskContext;
use App\Desk\Strategies\JsonRuleEvaluator;
use App\Desk\Strategies\PluginMarkdownExporter;
use App\Desk\Strategies\PluginPineExporter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JsonPluginUtilsTest extends TestCase
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

    private function ctx(): DeskContext
    {
        return new DeskContext([], 'paper');
    }

    #[Test]
    public function rules_fire_on_stats_and_indicators(): void
    {
        $s = $this->stats();
        $ctx = $this->ctx();

        $this->assertTrue(JsonRuleEvaluator::fires(['field' => 'indicators.rsi14', 'op' => '<', 'value' => 35], $s, null, $ctx));
        $this->assertFalse(JsonRuleEvaluator::fires(['field' => 'indicators.rsi14', 'op' => '>', 'value' => 35], $s, null, $ctx));
        $this->assertTrue(JsonRuleEvaluator::fires(['field' => 'spread_bps', 'op' => '<=', 'value' => 10], $s, null, $ctx));
    }

    #[Test]
    public function missing_fields_never_fire(): void
    {
        $s = $this->stats();
        $ctx = $this->ctx();

        $this->assertFalse(JsonRuleEvaluator::fires(['field' => 'indicators.macd', 'op' => '<', 'value' => 0], $s, null, $ctx));
        $this->assertFalse(JsonRuleEvaluator::fires(['field' => 'nope', 'op' => '==', 'value' => 1], $s, null, $ctx));
        $this->assertFalse(JsonRuleEvaluator::fires(['field' => 'position.pnl_pct', 'op' => '>', 'value' => 0], $s, null, $ctx));
    }

    #[Test]
    public function pine_export_maps_indicators_and_warns_on_tape(): void
    {
        $pine = PluginPineExporter::export([
            'key' => 'rsi-dip',
            'name' => 'RSI Dip',
            'scan' => ['filters' => [['field' => 'indicators.rsi14', 'op' => '<', 'value' => 35]]],
            'vet' => ['rules' => [['field' => 'spread_bps', 'op' => '<=', 'value' => 15]]],
            'risk' => ['rules' => [['field' => 'indicators.rsi14', 'op' => '>', 'value' => 70, 'action' => 'close']]],
        ]);

        $this->assertStringContainsString('//@version=6', $pine);
        $this->assertStringContainsString('ta.rsi(close, 14)', $pine);
        $this->assertStringContainsString('strategy.entry', $pine);
        $this->assertStringContainsString('WARNING', $pine);
        $this->assertStringContainsString('spread_bps', $pine);
    }

    #[Test]
    public function markdown_export_lists_every_rule(): void
    {
        $md = PluginMarkdownExporter::export([
            'key' => 'rsi-dip',
            'name' => 'RSI Dip',
            'description' => 'Buys dips.',
            'version' => 1,
            'base' => 'custom',
            'scan' => ['filters' => [['field' => 'indicators.rsi14', 'op' => '<', 'value' => 35]]],
            'vet' => ['rules' => [['field' => 'spread_bps', 'op' => '<=', 'value' => 15, 'reason' => 'wide']]],
            'size' => ['kelly_fraction' => 0.25],
            'risk' => ['rules' => [['field' => 'volume_ratio_6h', 'op' => '<', 'value' => 0.2, 'action' => 'close']]],
        ]);

        $this->assertStringContainsString('# RSI Dip', $md);
        $this->assertStringContainsString('indicators.rsi14 < 35', $md);
        $this->assertStringContainsString('spread_bps <= 15', $md);
        $this->assertStringContainsString('volume_ratio_6h < 0.2', $md);
    }
}
