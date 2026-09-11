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
    public function position_fields_are_rejected_outside_exit_and_management(): void
    {
        $def = $this->validDefinition();
        $def['trigger']['rules'][] = ['field' => 'position.pnl_pct', 'op' => '>', 'value' => 0];

        $result = StrategySchemaValidator::validate($def);

        $this->assertFalse($result['valid']);
        $this->assertContains('trigger.rules[2].field', array_column($result['errors'], 'path'));
    }
}
