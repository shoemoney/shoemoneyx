<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\StrategyRegistry;
use App\Support\ParamNormalizer;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Finding 3: BacktestController used to type an override purely from what the string LOOKED like
 * ("1"/"0" -> bool, digits -> number), which mis-typed a numeric knob given "1"/"0" as a bool and
 * broke a string knob given a numeric-looking value (mr.timeframe="1" must stay the string "1",
 * not become int/bool 1). ParamNormalizer instead types from the knob's own DEFAULT.
 */
class ParamNormalizerTest extends TestCase
{
    // --- typed from a registered strategy's defaults() ---

    public function test_bool_default_accepts_the_full_boolean_vocabulary(): void
    {
        // mr.allow_shorts default is false (bool). A list of pairs, not an assoc array — PHP casts
        // numeric-string keys like '1'/'0' to int keys, which would silently turn the input into a
        // real int and bypass the string-parsing path entirely.
        $cases = [
            ['true', true], ['TRUE', true], ['on', true], ['On', true], ['1', true], ['yes', true],
            ['false', false], ['FALSE', false], ['off', false], ['0', false], ['no', false],
        ];
        foreach ($cases as [$in, $expect]) {
            $this->assertSame($expect, ParamNormalizer::normalize('mr.allow_shorts', $in), "input \"{$in}\"");
        }
    }

    public function test_bool_default_rejects_anything_outside_the_vocabulary(): void
    {
        $this->expectException(ValidationException::class);
        ParamNormalizer::normalize('mr.allow_shorts', 'maybe');
    }

    public function test_numeric_string_on_a_numeric_default_becomes_a_number_not_a_bool(): void
    {
        // mr.leverage default is 1.0 (float) — "1"/"0" here must NOT become true/false.
        $this->assertSame(1, ParamNormalizer::normalize('mr.leverage', '1'));
        $this->assertSame(0, ParamNormalizer::normalize('mr.leverage', '0'));
        $this->assertSame(3, ParamNormalizer::normalize('mr.leverage', '3'));
        $this->assertSame(2.5, ParamNormalizer::normalize('mr.leverage', '2.5'));
    }

    public function test_malformed_number_on_a_numeric_default_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        ParamNormalizer::normalize('mr.leverage', '1.2.3');
    }

    public function test_string_default_keeps_the_literal_string_even_when_it_looks_numeric(): void
    {
        // mr.timeframe default is '1m' (string) — "1" must stay the string "1", not become int/bool 1.
        $this->assertSame('1', ParamNormalizer::normalize('mr.timeframe', '1'));
        $this->assertSame('0', ParamNormalizer::normalize('mr.timeframe', '0'));
        $this->assertSame('true', ParamNormalizer::normalize('mr.timeframe', 'true'));
        $this->assertSame('5m', ParamNormalizer::normalize('mr.timeframe', '5m'));
    }

    public function test_string_default_allows_an_empty_override_value(): void
    {
        // A string-typed knob's default() type wins even when the override is '' — '' must not be
        // rejected the way an empty value on a bool/numeric knob is.
        $this->assertSame('', ParamNormalizer::normalize('mr.timeframe', ''));
    }

    public function test_short_prefixed_key_falls_back_to_the_long_knobs_type(): void
    {
        // mr.short.* is never in defaults() itself — it must fall back to mr.* for typing.
        $this->assertSame(true, ParamNormalizer::normalize('mr.short.allow_shorts', 'true'));
        $this->assertSame('', ParamNormalizer::normalize('mr.short.timeframe', ''));
        $this->assertSame(2, ParamNormalizer::normalize('mr.short.leverage', '2'));
    }

    public function test_per_product_prefixed_key_is_typed_like_the_bare_key(): void
    {
        $this->assertSame(true, ParamNormalizer::normalize('per_product.BTC-USD.mr.allow_shorts', 'true'));
        $this->assertSame('', ParamNormalizer::normalize('per_product.BTC-USD.mr.timeframe', ''));
        $this->assertSame(true, ParamNormalizer::normalize('per_product.BTC-USD.mr.short.allow_shorts', 'true'));
    }

    // --- typed from config('desk') for non-strategy namespaces ---

    public function test_config_bool_default(): void
    {
        // perps.whole_contracts default is true (bool)
        $this->assertSame(false, ParamNormalizer::normalize('perps.whole_contracts', '0'));
        $this->assertSame(true, ParamNormalizer::normalize('perps.whole_contracts', '1'));
    }

    public function test_config_numeric_default(): void
    {
        // size.kelly_cap_pct default is 0.06 (float)
        $this->assertSame(0.04, ParamNormalizer::normalize('size.kelly_cap_pct', '0.04'));
    }

    // --- unresolvable keys fall back to the old value-shape guess ---

    public function test_unresolved_key_falls_back_to_guessing_from_the_values_shape(): void
    {
        $this->assertSame(true, ParamNormalizer::normalize('perps.margin', 'ON'));
        $this->assertSame('some-string', ParamNormalizer::normalize('scan.made_up_knob', 'some-string'));
    }

    public function test_unresolved_key_with_an_empty_value_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        ParamNormalizer::normalize('scan.made_up_knob', '');
    }

    // --- pass-through for already-typed values ---

    public function test_non_string_values_pass_through_untouched(): void
    {
        $this->assertSame(true, ParamNormalizer::normalize('mr.allow_shorts', true));
        $this->assertSame(3, ParamNormalizer::normalize('mr.leverage', 3));
        $this->assertNull(ParamNormalizer::normalize('mr.leverage', null));
        $this->assertSame([1, 2], ParamNormalizer::normalize('universe.exclude', [1, 2]));
    }

    /**
     * Round-trip requirement from finding 3: normalising every leaf UNDER A STRATEGY'S OWN PREFIX
     * (e.g. every "mr.*" key in MeanReversionStrategy::defaults(), re-serialised as a string the way
     * an HTTP form or a JSON-with-string-values caller would send it back) must not change its kind —
     * a bool stays a bool and a string stays the exact same string (this is what protects an
     * empty-string override and catches the "false" → truthy-string regression). A numeric default is
     * only checked for the same numeric VALUE, not int-vs-float: "3" is deliberately int(3) even
     * when overriding a float-defaulted knob like mr.leverage (see
     * BacktestApiTest::test_on_off_strings_become_booleans_and_numeric_strings_become_numbers) —
     * the string's own shape decides int vs float, same as it always did.
     *
     * Scoped to the strategy's own prefix because a strategy's defaults() can also inject
     * cross-cutting overrides into shared namespaces (e.g. a top-level 'scan' => ['min_score' => 0.0])
     * that aren't part of ParamNormalizer's documented "<strategy-key>." contract and legitimately
     * fall back to the old value-shape guess instead.
     */
    public function test_round_trip_preserves_the_defaults_kind_for_every_registered_strategy(): void
    {
        $registry = app(StrategyRegistry::class);
        foreach (array_keys($registry->all()) as $key) {
            $flat = Arr::dot($registry->make($key)->defaults());
            foreach ($flat as $dottedKey => $default) {
                if (! str_starts_with($dottedKey, $key.'.')) {
                    continue; // not one of this strategy's own knobs — outside the documented contract
                }
                if (is_bool($default)) {
                    $normalized = ParamNormalizer::normalize($dottedKey, $default ? 'true' : 'false');
                    $this->assertSame($default, $normalized, "bool round-trip mismatch for {$dottedKey}");
                } elseif (is_int($default) || is_float($default)) {
                    // (string) uses the `precision` ini setting (14 significant digits), which can
                    // lose bits for an irrational float default — that's a property of string
                    // round-tripping a double, not something ParamNormalizer can fix, so compare
                    // with a tolerance instead of exact equality.
                    $normalized = ParamNormalizer::normalize($dottedKey, (string) $default);
                    $this->assertIsNumeric($normalized, "numeric round-trip mismatch for {$dottedKey}");
                    $this->assertEqualsWithDelta((float) $default, (float) $normalized, 1e-9, "numeric round-trip value mismatch for {$dottedKey}");
                } elseif (is_string($default)) {
                    $normalized = ParamNormalizer::normalize($dottedKey, $default);
                    $this->assertSame($default, $normalized, "string round-trip mismatch for {$dottedKey}");
                }
                // else: a nested array default (e.g. an indicator's own DEFAULTS constant) too deep
                // for a leaf override.
            }
        }
    }
}
