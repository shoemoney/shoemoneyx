<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Strategies\StrategySchemaValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StrategySchemaValidatorV2Test extends TestCase
{
    private function validDefinition(): array
    {
        return json_decode(file_get_contents(base_path('resources/strategies/examples/smx-pi-take-profit-v2.json')), true);
    }

    private function paths(array $result): array
    {
        return array_column($result['errors'], 'path');
    }

    #[Test]
    public function the_shipped_smx_pi_example_validates(): void
    {
        $result = StrategySchemaValidator::validate($this->validDefinition());

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertSame([], $result['errors']);
    }

    #[Test]
    public function schema_version_2_dispatches_to_the_v2_validator_and_1_still_dispatches_to_v1(): void
    {
        $v1 = json_decode(file_get_contents(base_path('resources/strategies/examples/mean-reversion.json')), true);

        $this->assertTrue(StrategySchemaValidator::validate($v1)['valid']);
        $this->assertTrue(StrategySchemaValidator::validate($this->validDefinition())['valid']);
    }

    #[Test]
    public function unknown_signal_name_in_entry_when_is_reported_with_its_index_path(): void
    {
        $def = $this->validDefinition();
        $def['entry']['when'][] = 'not_a_real_signal';

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('entry.when[3]', $this->paths($result));
    }

    #[Test]
    public function entry_when_missing_is_rejected_rather_than_treated_as_buy_everything(): void
    {
        $def = $this->validDefinition();
        unset($def['entry']['when']);

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('entry.when', $this->paths($result));
    }

    #[Test]
    public function entry_when_empty_is_rejected_rather_than_treated_as_buy_everything(): void
    {
        $def = $this->validDefinition();
        $def['entry']['when'] = [];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('entry.when', $this->paths($result));
    }

    #[Test]
    public function signal_name_entry_is_reserved(): void
    {
        $def = $this->validDefinition();
        $def['signals']['entry'] = ['all' => [['field' => 'price', 'op' => '>', 'value' => 1]]];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('signals.entry', $this->paths($result));
    }

    #[Test]
    public function ladder_at_pct_must_strictly_increase(): void
    {
        $def = $this->validDefinition();
        $def['take_profit']['ladder'][1]['at_pct'] = 0.5;

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('take_profit.ladder[1].at_pct', $this->paths($result));
    }

    #[Test]
    public function ladder_at_pct_of_zero_is_rejected(): void
    {
        $def = $this->validDefinition();
        $def['take_profit']['ladder'][0]['at_pct'] = 0;

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('take_profit.ladder[0].at_pct', $this->paths($result));
    }

    #[Test]
    public function ladder_at_pct_negative_is_rejected(): void
    {
        $def = $this->validDefinition();
        $def['take_profit']['ladder'][0]['at_pct'] = -1;

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('take_profit.ladder[0].at_pct', $this->paths($result));
    }

    #[Test]
    public function ladder_sell_pct_of_original_cannot_sum_over_100(): void
    {
        $def = $this->validDefinition();
        $def['take_profit']['ladder'][3]['sell_pct_of_original'] = 90;

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('take_profit.ladder', $this->paths($result));
    }

    #[Test]
    public function reentry_requires_a_non_empty_take_profit_ladder(): void
    {
        $def = $this->validDefinition();
        $def['take_profit']['ladder'] = [];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('reentry', $this->paths($result));
    }

    #[Test]
    public function sizing_object_rejects_an_unknown_mode(): void
    {
        $def = $this->validDefinition();
        $def['entry']['size'] = ['mode' => 'moon', 'value' => 5];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('entry.size.mode', $this->paths($result));
    }

    #[Test]
    public function kelly_sizing_requires_a_positive_fraction(): void
    {
        $def = $this->validDefinition();
        $def['entry']['size'] = ['mode' => 'kelly', 'fraction' => -1];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('entry.size.fraction', $this->paths($result));
    }

    #[Test]
    public function adds_rung_requires_size_pct_or_a_sizing_object(): void
    {
        $def = $this->validDefinition();
        $def['adds'] = [['trigger' => ['field' => 'price', 'op' => '<', 'value' => 100]]];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('adds[0].size_pct', $this->paths($result));
    }

    #[Test]
    public function adds_rung_accepts_a_sizing_object_instead_of_size_pct(): void
    {
        $def = $this->validDefinition();
        $def['adds'] = [[
            'trigger' => ['field' => 'position.pnl_pct', 'op' => '<', 'value' => -1],
            'size' => ['mode' => 'usd', 'value' => 100],
        ]];

        $result = StrategySchemaValidator::validate($def);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    #[Test]
    public function stop_anchor_must_be_avg_or_entry(): void
    {
        $def = $this->validDefinition();
        $def['stop']['anchor'] = 'moon';

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('stop.anchor', $this->paths($result));
    }

    #[Test]
    public function crosses_above_requires_a_field_ref_value(): void
    {
        $def = $this->validDefinition();
        $def['signals']['not_overbought']['all'][] = ['field' => 'ind.ema(20)', 'op' => 'crosses_above', 'value' => 50];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('signals.not_overbought.all[1].value', $this->paths($result));
    }

    #[Test]
    public function crosses_above_accepts_a_field_ref_value(): void
    {
        $def = $this->validDefinition();
        $def['signals']['not_overbought']['all'][] = ['field' => 'ind.ema(20)', 'op' => 'crosses_above', 'value' => ['field' => 'ind.ema(50)']];

        $result = StrategySchemaValidator::validate($def);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    #[Test]
    public function unknown_indicator_is_rejected(): void
    {
        $def = $this->validDefinition();
        $def['signals']['liquid']['all'][] = ['field' => 'ind.stochrsi(14)', 'op' => '<', 'value' => 50];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('signals.liquid.all[1].field', $this->paths($result));
    }

    #[Test]
    public function ind_field_with_wrong_arity_is_rejected(): void
    {
        $def = $this->validDefinition();
        $def['signals']['liquid']['all'][] = ['field' => 'ind.macd(12,26)', 'op' => '<', 'value' => 0];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('signals.liquid.all[1].field', $this->paths($result));
    }

    #[Test]
    public function ind_field_requiring_an_output_suffix_is_rejected_when_bare(): void
    {
        $def = $this->validDefinition();
        $def['signals']['liquid']['all'][] = ['field' => 'ind.macd(12,26,9)', 'op' => '<', 'value' => 0];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('signals.liquid.all[1].field', $this->paths($result));
    }

    #[Test]
    public function ind_field_with_valid_output_suffix_passes(): void
    {
        $def = $this->validDefinition();
        $def['signals']['liquid']['all'][] = ['field' => 'ind.macd(12,26,9).hist', 'op' => '>', 'value' => 0];

        $result = StrategySchemaValidator::validate($def);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    #[Test]
    public function ind_field_period_argument_of_zero_is_rejected(): void
    {
        $def = $this->validDefinition();
        $def['signals']['liquid']['all'][] = ['field' => 'ind.rsi(0)', 'op' => '<', 'value' => 50];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('signals.liquid.all[1].field', $this->paths($result));
    }

    #[Test]
    public function ind_bb_rejects_a_zero_multiplier_but_allows_a_fractional_one(): void
    {
        $def = $this->validDefinition();
        $def['signals']['liquid']['all'][] = ['field' => 'ind.bb(20,0).mid', 'op' => '>', 'value' => 0];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('signals.liquid.all[1].field', $this->paths($result));

        $def2 = $this->validDefinition();
        $def2['signals']['liquid']['all'][] = ['field' => 'ind.bb(20,1.5).mid', 'op' => '>', 'value' => 0];

        $this->assertTrue(StrategySchemaValidator::validate($def2)['valid']);
    }

    #[Test]
    public function an_unknown_per_rule_tf_is_rejected(): void
    {
        $def = $this->validDefinition();
        $def['signals']['liquid']['all'][] = ['field' => 'ind.rsi(14)', 'op' => '<', 'value' => 50, 'tf' => 'banana'];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('signals.liquid.all[1].tf', $this->paths($result));
    }

    #[Test]
    public function an_embedded_unresolved_param_reference_in_a_rule_value_is_rejected(): void
    {
        $def = $this->validDefinition();
        $def['signals']['liquid']['all'][] = ['field' => 'price', 'op' => '>', 'value' => 'over $nope threshold'];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('signals.liquid.all[1].value', $this->paths($result));
    }

    #[Test]
    public function an_unreferenced_signal_produces_a_warning_but_stays_valid(): void
    {
        $def = $this->validDefinition();
        $def['signals']['unused'] = ['all' => [['field' => 'price', 'op' => '>', 'value' => 0]]];

        $result = StrategySchemaValidator::validate($def);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertSame([], $result['errors']);
        $this->assertContains(
            ['path' => 'signals.unused', 'message' => 'signal is never referenced'],
            $result['warnings']
        );
    }

    #[Test]
    public function the_shipped_example_has_no_unreferenced_signal_warnings(): void
    {
        $result = StrategySchemaValidator::validate($this->validDefinition());

        $this->assertSame([], $result['warnings']);
    }

    /** Round-4 review, finding 6: stop.rules is fail-CLOSED (only closes when a rule matches), so no stop at all is a fail-OPEN risk — valid, but worth a warning, not silence. */
    #[Test]
    public function no_stop_section_at_all_produces_a_warning_but_stays_valid(): void
    {
        $def = $this->validDefinition();
        unset($def['stop']);

        $result = StrategySchemaValidator::validate($def);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertContains(
            ['path' => 'stop', 'message' => 'no stop.pct_from_avg or stop.time_hours fail-safe: stop.rules alone (or no stop at all) can fail open and hold a losing position indefinitely'],
            $result['warnings']
        );
    }

    #[Test]
    public function a_stop_with_only_rules_on_ind_fields_and_no_fail_safe_produces_a_warning(): void
    {
        $def = $this->validDefinition();
        $def['stop'] = ['rules' => [['field' => 'ind.rsi(14)', 'op' => '>', 'value' => 80]]];

        $result = StrategySchemaValidator::validate($def);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertContains(
            ['path' => 'stop', 'message' => 'no stop.pct_from_avg or stop.time_hours fail-safe: stop.rules alone (or no stop at all) can fail open and hold a losing position indefinitely'],
            $result['warnings']
        );
    }

    #[Test]
    public function a_stop_with_pct_from_avg_alongside_rules_produces_no_fail_safe_warning(): void
    {
        $def = $this->validDefinition();
        $def['stop']['rules'] = [['field' => 'ind.rsi(14)', 'op' => '>', 'value' => 80]];
        $this->assertArrayHasKey('pct_from_avg', $def['stop'], 'the shipped example must already carry a pct_from_avg fail-safe, or this test proves nothing');

        $result = StrategySchemaValidator::validate($def);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertNotContains('stop', array_column($result['warnings'], 'path'));
    }

    #[Test]
    public function a_stop_with_only_time_hours_produces_no_fail_safe_warning(): void
    {
        $def = $this->validDefinition();
        unset($def['stop']['pct_from_avg']);
        $def['stop']['time_hours'] = 48;

        $result = StrategySchemaValidator::validate($def);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertNotContains('stop', array_column($result['warnings'], 'path'));
    }

    #[Test]
    public function the_shipped_pi_example_produces_no_stop_fail_safe_warning(): void
    {
        $result = StrategySchemaValidator::validate($this->validDefinition());

        $this->assertNotContains('stop', array_column($result['warnings'], 'path'));
    }

    #[Test]
    public function an_unresolved_param_reference_in_a_not_in_value_is_rejected(): void
    {
        $def = $this->validDefinition();
        $def['signals']['liquid']['all'][] = ['field' => 'price', 'op' => 'not_in', 'value' => ['$nope']];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('signals.liquid.all[1].value[0]', $this->paths($result));
    }

    #[Test]
    public function an_unresolved_param_reference_in_a_between_value_is_rejected(): void
    {
        $def = $this->validDefinition();
        $def['signals']['liquid']['all'][] = ['field' => 'price', 'op' => 'between', 'value' => ['$lo', '$hi']];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('signals.liquid.all[1].value[0]', $this->paths($result));
        $this->assertContains('signals.liquid.all[1].value[1]', $this->paths($result));
    }

    /**
     * Round-6 review, BLOCKER 2: an object value (e.g. {"lo":1,"hi":1000}, decoded to an
     * associative array with no index 0/1) has count() 2 and validated clean, then threw
     * "Undefined array key 0" at runtime (JsonRuleEvaluator).
     */
    #[Test]
    public function between_rejects_a_two_key_object_value(): void
    {
        $def = $this->validDefinition();
        $def['signals']['liquid']['all'][] = ['field' => 'price', 'op' => 'between', 'value' => ['lo' => 1, 'hi' => 1000]];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('signals.liquid.all[1].value', $this->paths($result));
    }

    #[Test]
    public function adds_rung_rejects_both_size_pct_and_size_at_once(): void
    {
        $def = $this->validDefinition();
        $def['adds'] = [[
            'trigger' => ['field' => 'position.pnl_pct', 'op' => '<', 'value' => -1],
            'size_pct' => 50,
            'size' => ['mode' => 'usd', 'value' => 100],
        ]];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('adds[0].size', $this->paths($result));
    }

    #[Test]
    public function v1_indicator_aliases_still_validate_in_v2(): void
    {
        $def = $this->validDefinition();
        $def['signals']['liquid']['all'][] = ['field' => 'indicators.rsi14', 'op' => '<', 'value' => 80];

        $result = StrategySchemaValidator::validate($def);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    #[Test]
    public function position_fields_are_rejected_in_a_signal_referenced_from_entry_when(): void
    {
        // `liquid` is in the shipped example's entry.when, and scan runs with no position yet,
        // so a position.* field there can never hold and would silently kill the strategy.
        $def = $this->validDefinition();
        $def['signals']['liquid']['all'][] = ['field' => 'position.pnl_pct', 'op' => '>', 'value' => 0];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('signals.liquid.all[1].field', $this->paths($result));
    }

    #[Test]
    public function position_fields_stay_permitted_in_a_signal_referenced_only_from_reentry_when(): void
    {
        $def = $this->validDefinition();
        $def['signals']['re_only'] = ['all' => [['field' => 'position.pnl_pct', 'op' => '>', 'value' => 0]]];
        $def['reentry']['when'] = ['re_only'];

        $result = StrategySchemaValidator::validate($def);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    #[Test]
    public function position_fields_are_rejected_in_entry_confirm(): void
    {
        $def = $this->validDefinition();
        $def['entry']['confirm'][] = ['field' => 'position.pnl_pct', 'op' => '>', 'value' => 0];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('entry.confirm[1].field', $this->paths($result));
    }

    #[Test]
    public function an_unresolved_param_reference_in_a_rule_value_is_a_validation_error(): void
    {
        $def = $this->validDefinition();
        $def['stop']['pct_from_avg'] = '$nonexistent_param';

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $errors = $result['errors'];
        $match = array_filter($errors, fn ($e) => $e['path'] === 'stop.pct_from_avg' && str_contains($e['message'], 'nonexistent_param'));
        $this->assertNotEmpty($match);
    }

    #[Test]
    public function a_known_param_reference_substitutes_its_default_and_validates(): void
    {
        $def = $this->validDefinition();
        $def['stop']['pct_from_avg'] = '$fail_safe_pct';

        $result = StrategySchemaValidator::validate($def);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    #[Test]
    public function formula_expr_with_an_unknown_variable_is_rejected(): void
    {
        $def = $this->validDefinition();
        // `avg` is only allowed in adds/reentry formulas, not entry (docs/STRATEGY_SCHEMA_V2.md, "Formulas" table).
        $def['entry']['size'] = ['mode' => 'formula', 'expr' => 'avg * 0.1'];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('entry.size.expr', $this->paths($result));
    }

    #[Test]
    public function formula_expr_that_fails_to_parse_is_rejected(): void
    {
        $def = $this->validDefinition();
        $def['reentry']['size'] = ['mode' => 'formula', 'expr' => 'sold_qty * ('];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('reentry.size.expr', $this->paths($result));
    }

    #[Test]
    public function definition_must_be_an_object(): void
    {
        $result = StrategySchemaValidator::validate('nope');

        $this->assertFalse($result['valid']);
        $this->assertSame('$', $result['errors'][0]['path']);
    }

    #[Test]
    public function meta_and_entry_stay_required_in_v2(): void
    {
        $def = $this->validDefinition();
        unset($def['meta']['name'], $def['entry']);

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('meta.name', $this->paths($result));
        $this->assertContains('entry', $this->paths($result));
    }
}
