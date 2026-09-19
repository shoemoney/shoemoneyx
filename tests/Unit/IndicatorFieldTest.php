<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Strategies\IndicatorField;
use Tests\TestCase;

/** IndicatorField::parse() against the closed `ind.*` grammar (docs/STRATEGY_SCHEMA_V2.md). */
class IndicatorFieldTest extends TestCase
{
    public function test_non_ind_field_is_null_not_an_error(): void
    {
        $this->assertNull(IndicatorField::parse('price'));
        $this->assertNull(IndicatorField::parse('indicators.rsi14'));
    }

    public function test_single_output_indicator_defaults_to_value(): void
    {
        $f = IndicatorField::parse('ind.rsi(14)');

        $this->assertSame('rsi', $f->name);
        $this->assertSame([14.0], $f->args);
        $this->assertSame('value', $f->output);
    }

    public function test_zero_arity_indicator_with_no_parens(): void
    {
        $f = IndicatorField::parse('ind.vwap');

        $this->assertSame('vwap', $f->name);
        $this->assertSame([], $f->args);
        $this->assertSame('value', $f->output);
    }

    public function test_atr_accepts_default_and_pct_output(): void
    {
        $this->assertSame('value', IndicatorField::parse('ind.atr(14)')->output);
        $this->assertSame('pct', IndicatorField::parse('ind.atr(14).pct')->output);
    }

    public function test_multi_output_indicator_requires_a_suffix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IndicatorField::parse('ind.macd(12,26,9)');
    }

    public function test_macd_with_explicit_output(): void
    {
        $f = IndicatorField::parse('ind.macd(12,26,9).signal');

        $this->assertSame([12.0, 26.0, 9.0], $f->args);
        $this->assertSame('signal', $f->output);
    }

    public function test_smx_zero_arity_with_named_output(): void
    {
        $f = IndicatorField::parse('ind.smx.buy');

        $this->assertSame('smx', $f->name);
        $this->assertSame([], $f->args);
        $this->assertSame('buy', $f->output);
    }

    public function test_unknown_indicator_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IndicatorField::parse('ind.bogus(1)');
    }

    public function test_unknown_output_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IndicatorField::parse('ind.macd(12,26,9).bogus');
    }

    public function test_wrong_arity_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IndicatorField::parse('ind.rsi(14,20)');
    }

    public function test_missing_required_arity_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IndicatorField::parse('ind.rsi');
    }

    public function test_non_numeric_argument_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IndicatorField::parse('ind.rsi(abc)');
    }

    public function test_malformed_syntax_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IndicatorField::parse('ind.rsi(14');
    }

    public function test_series_key_ignores_output(): void
    {
        $a = IndicatorField::parse('ind.atr(14)');
        $b = IndicatorField::parse('ind.atr(14).pct');

        $this->assertSame($a->seriesKey(), $b->seriesKey());
    }
}
