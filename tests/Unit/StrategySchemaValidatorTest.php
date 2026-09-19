<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Strategies\StrategySchemaValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StrategySchemaValidatorTest extends TestCase
{
    private function validDefinition(): array
    {
        return json_decode(file_get_contents(base_path('resources/strategies/examples/mean-reversion.json')), true);
    }

    #[Test]
    public function the_shipped_example_validates(): void
    {
        $result = StrategySchemaValidator::validate($this->validDefinition());

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertSame([], $result['errors']);
    }

    #[Test]
    public function meta_errors_carry_the_meta_path(): void
    {
        $def = $this->validDefinition();
        unset($def['meta']['name']);

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('meta.name', array_column($result['errors'], 'path'));
    }

    #[Test]
    public function entry_is_required(): void
    {
        $def = $this->validDefinition();
        unset($def['entry']);

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('entry', array_column($result['errors'], 'path'));
    }

    #[Test]
    public function a_bad_op_in_a_trigger_rule_is_reported_with_its_exact_path(): void
    {
        $def = $this->validDefinition();
        $def['trigger']['rules'][0]['op'] = 'wat';

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('trigger.rules[0].op', array_column($result['errors'], 'path'));
    }

    #[Test]
    public function between_requires_a_two_element_array_value(): void
    {
        $def = $this->validDefinition();
        $def['setup']['rules'][1]['value'] = [1, 2, 3];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('setup.rules[1].value', array_column($result['errors'], 'path'));
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
        $def['setup']['rules'][1]['value'] = ['lo' => 1, 'hi' => 1000];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('setup.rules[1].value', array_column($result['errors'], 'path'));
    }

    #[Test]
    public function risk_caps_must_be_positive(): void
    {
        $def = $this->validDefinition();
        $def['risk']['max_positions'] = -1;
        $def['risk']['leverage_cap'] = 0;

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('risk.max_positions', array_column($result['errors'], 'path'));
        $this->assertContains('risk.leverage_cap', array_column($result['errors'], 'path'));
    }

    #[Test]
    public function unknown_param_type_is_reported(): void
    {
        $def = $this->validDefinition();
        $def['params']['rsi_entry']['type'] = 'array';

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('params.rsi_entry.type', array_column($result['errors'], 'path'));
    }

    #[Test]
    public function crosses_above_on_a_non_indicator_field_is_rejected(): void
    {
        $def = $this->validDefinition();
        $def['trigger']['rules'][0] = ['field' => 'price', 'op' => 'crosses_above', 'value' => 30];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('trigger.rules[0].field', array_column($result['errors'], 'path'));
    }

    #[Test]
    public function crosses_above_with_a_non_indicator_value_field_is_rejected(): void
    {
        $def = $this->validDefinition();
        $def['trigger']['rules'][0] = ['field' => 'ind.ema(20)', 'op' => 'crosses_above', 'value' => ['field' => 'price']];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('trigger.rules[0].value', array_column($result['errors'], 'path'));
    }

    #[Test]
    public function position_fields_are_rejected_outside_exit_and_management(): void
    {
        $def = $this->validDefinition();
        $def['trigger']['rules'][] = ['field' => 'position.pnl_pct', 'op' => '>', 'value' => 0];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('trigger.rules[2].field', array_column($result['errors'], 'path'));
    }

    #[Test]
    public function a_literal_comparison_against_an_ind_field_validates_clean(): void
    {
        $def = $this->validDefinition();
        $def['trigger']['rules'][0] = ['field' => 'ind.rsi(14)', 'op' => '<', 'value' => 30];

        $result = StrategySchemaValidator::validate($def);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    #[Test]
    public function crosses_above_between_two_ind_fields_validates_clean(): void
    {
        $def = $this->validDefinition();
        $def['trigger']['rules'][0] = ['field' => 'ind.ema(20)', 'op' => 'crosses_above', 'value' => ['field' => 'ind.ema(50)']];

        $result = StrategySchemaValidator::validate($def);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    #[Test]
    public function an_unknown_ind_field_is_rejected(): void
    {
        $def = $this->validDefinition();
        $def['trigger']['rules'][0] = ['field' => 'ind.bogus(1)', 'op' => '<', 'value' => 30];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('trigger.rules[0].field', array_column($result['errors'], 'path'));
    }

    #[Test]
    public function a_field_to_field_value_validates_for_a_non_crosses_op(): void
    {
        $def = $this->validDefinition();
        $def['trigger']['rules'][0] = ['field' => 'ind.ema(20)', 'op' => '<', 'value' => ['field' => 'ind.ema(50)']];

        $result = StrategySchemaValidator::validate($def);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    #[Test]
    public function a_field_to_field_value_referencing_an_unknown_field_is_rejected(): void
    {
        $def = $this->validDefinition();
        $def['trigger']['rules'][0] = ['field' => 'ind.ema(20)', 'op' => '<', 'value' => ['field' => 'ind.bogus(1)']];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('trigger.rules[0].value.field', array_column($result['errors'], 'path'));
    }

    #[Test]
    public function a_bad_meta_timeframe_is_rejected(): void
    {
        $def = $this->validDefinition();
        $def['meta']['timeframe'] = '3h';

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('meta.timeframe', array_column($result['errors'], 'path'));
    }

    #[Test]
    public function a_bad_rule_tf_is_rejected(): void
    {
        $def = $this->validDefinition();
        $def['trigger']['rules'][0] = ['field' => 'ind.rsi(14)', 'tf' => '15minutes', 'op' => '<', 'value' => 30];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('trigger.rules[0].tf', array_column($result['errors'], 'path'));
    }

    #[Test]
    public function a_known_rule_tf_validates_clean(): void
    {
        $def = $this->validDefinition();
        $def['trigger']['rules'][0] = ['field' => 'ind.rsi(14)', 'tf' => '15m', 'op' => '<', 'value' => 30];

        $result = StrategySchemaValidator::validate($def);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    #[Test]
    public function a_literal_comparison_against_ind_obv_is_rejected(): void
    {
        $def = $this->validDefinition();
        $def['trigger']['rules'][0] = ['field' => 'ind.obv', 'op' => '>', 'value' => 0];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('trigger.rules[0].value', array_column($result['errors'], 'path'));
    }

    #[Test]
    public function ind_obv_via_crosses_above_still_validates_clean(): void
    {
        $def = $this->validDefinition();
        $def['trigger']['rules'][0] = ['field' => 'ind.obv', 'op' => 'crosses_above', 'value' => ['field' => 'ind.sma(20)']];

        $result = StrategySchemaValidator::validate($def);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }
}
