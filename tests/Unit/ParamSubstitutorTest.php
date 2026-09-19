<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Strategies\ParamSubstitutor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ParamSubstitutorTest extends TestCase
{
    #[Test]
    public function a_whole_string_param_reference_becomes_the_typed_default(): void
    {
        $definition = [
            'params' => ['fail_safe_pct' => ['type' => 'number', 'default' => 2.5]],
            'stop' => ['pct_from_avg' => '$fail_safe_pct'],
        ];

        $out = ParamSubstitutor::apply($definition);

        $this->assertSame(2.5, $out['stop']['pct_from_avg']);
    }

    #[Test]
    public function a_param_reference_inside_a_formula_expr_is_rendered_as_a_number_literal(): void
    {
        $definition = [
            'params' => ['fail_safe_pct' => ['type' => 'number', 'default' => 2.5]],
            'entry' => ['size' => ['mode' => 'formula', 'expr' => 'avg * (1 - $fail_safe_pct / 100)']],
        ];

        $out = ParamSubstitutor::apply($definition);

        $this->assertSame('avg * (1 - 2.5 / 100)', $out['entry']['size']['expr']);
    }

    #[Test]
    public function an_unknown_param_reference_is_left_untouched(): void
    {
        $definition = [
            'params' => [],
            'stop' => ['pct_from_avg' => '$nope'],
        ];

        $out = ParamSubstitutor::apply($definition);

        $this->assertSame('$nope', $out['stop']['pct_from_avg']);
    }

    #[Test]
    public function a_whole_string_bool_param_reference_keeps_its_boolean_type(): void
    {
        $definition = [
            'params' => ['flag' => ['type' => 'bool', 'default' => true]],
            'signals' => ['s' => ['all' => [['field' => 'x', 'op' => '==', 'value' => '$flag']]]],
        ];

        $out = ParamSubstitutor::apply($definition);

        $this->assertTrue($out['signals']['s']['all'][0]['value']);
    }

    #[Test]
    public function a_bool_param_embedded_in_a_formula_expr_renders_as_1_or_0(): void
    {
        $definition = [
            'params' => ['flag' => ['type' => 'bool', 'default' => true]],
            'entry' => ['size' => ['mode' => 'formula', 'expr' => 'price * $flag']],
        ];

        $out = ParamSubstitutor::apply($definition);

        $this->assertSame('price * 1', $out['entry']['size']['expr']);
    }

    #[Test]
    public function values_without_a_dollar_sign_are_left_alone(): void
    {
        $definition = ['params' => ['p' => ['type' => 'number', 'default' => 1]], 'entry' => ['side' => 'long']];

        $this->assertSame('long', ParamSubstitutor::apply($definition)['entry']['side']);
    }
}
