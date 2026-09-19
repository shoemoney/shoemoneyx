<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Strategies\Formula;
use App\Desk\Strategies\FormulaParseError;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FormulaTest extends TestCase
{
    #[Test]
    public function it_evaluates_arithmetic_with_the_documented_precedence(): void
    {
        $ast = Formula::parse('2 + 3 * 4');

        $this->assertSame(14.0, Formula::evaluate($ast, []));
    }

    #[Test]
    public function parens_override_precedence(): void
    {
        $this->assertSame(20.0, Formula::evaluate(Formula::parse('(2 + 3) * 4'), []));
    }

    #[Test]
    public function unary_minus_binds_tighter_than_multiplication(): void
    {
        // evaluate() fails closed on a non-positive result (see below), so the
        // negative-result cases are asserted through abs() to keep exercising
        // the grouping/precedence itself.
        $this->assertSame(8.0, Formula::evaluate(Formula::parse('abs(-(3 + 5))'), []));
        $this->assertSame(1.0, Formula::evaluate(Formula::parse('-3 + 4'), []));
        $this->assertSame(8.0, Formula::evaluate(Formula::parse('abs(-2 * 4)'), []));
    }

    #[Test]
    public function evaluate_fails_closed_on_a_non_positive_or_non_finite_result(): void
    {
        $this->assertNull(Formula::evaluate(Formula::parse('0'), []));
        $this->assertNull(Formula::evaluate(Formula::parse('sold_qty * (0 - 5)'), ['sold_qty' => 10]));

        $overflow = implode(' * ', array_fill(0, 31, '99999999999999999'));
        $this->assertNull(Formula::evaluate(Formula::parse($overflow), []));
    }

    #[Test]
    public function min_max_abs_work(): void
    {
        $this->assertSame(1.0, Formula::evaluate(Formula::parse('min(1, 2)'), []));
        $this->assertSame(2.0, Formula::evaluate(Formula::parse('max(1, 2)'), []));
        $this->assertSame(3.0, Formula::evaluate(Formula::parse('abs(-3)'), []));
    }

    #[Test]
    public function it_resolves_variables_from_the_vars_map(): void
    {
        $ast = Formula::parse('sold_qty * min(1, retrace_pct * pi / spacing_pct)');

        $result = Formula::evaluate($ast, ['sold_qty' => 10, 'retrace_pct' => 0.5, 'pi' => M_PI, 'spacing_pct' => 0.5]);

        $this->assertSame(10.0, $result);
    }

    #[Test]
    public function division_by_zero_yields_null_not_an_exception(): void
    {
        $this->assertNull(Formula::evaluate(Formula::parse('1 / 0'), []));
        $this->assertNull(Formula::evaluate(Formula::parse('avg / (price - price)'), ['avg' => 1, 'price' => 5]));
    }

    #[Test]
    public function a_missing_variable_propagates_as_null(): void
    {
        $this->assertNull(Formula::evaluate(Formula::parse('avg + 1'), []));
    }

    #[Test]
    public function unknown_function_throws_a_parse_error_at_its_offset(): void
    {
        $this->expectException(FormulaParseError::class);
        Formula::parse('wat(1, 2)');
    }

    #[Test]
    public function wrong_arity_throws_a_parse_error(): void
    {
        $this->expectException(FormulaParseError::class);
        Formula::parse('min(1)');
    }

    #[Test]
    public function trailing_garbage_throws_with_the_exact_offset(): void
    {
        try {
            Formula::parse('1 + 2 )');
            $this->fail('expected a FormulaParseError');
        } catch (FormulaParseError $e) {
            $this->assertSame(6, $e->offset);
        }
    }

    #[Test]
    public function unexpected_character_throws_a_parse_error(): void
    {
        $this->expectException(FormulaParseError::class);
        Formula::parse('avg & price');
    }

    #[Test]
    public function empty_expression_throws_a_parse_error(): void
    {
        $this->expectException(FormulaParseError::class);
        Formula::parse('   ');
    }

    #[Test]
    public function allowed_variables_are_scoped_per_section(): void
    {
        $this->assertSame(['equity', 'cash', 'price', 'pi', 'fees_rt_pct'], Formula::allowedVariables('entry'));
        $this->assertContains('avg', Formula::allowedVariables('adds'));
        $this->assertNotContains('spacing_pct', Formula::allowedVariables('adds'));
        $this->assertContains('spacing_pct', Formula::allowedVariables('reentry'));
    }

    #[Test]
    public function variables_used_reports_every_identifier_with_its_offset(): void
    {
        $ast = Formula::parse('avg + price');

        $vars = Formula::variablesUsed($ast);

        $this->assertSame(['avg', 'price'], array_column($vars, 'name'));
        $this->assertSame(0, $vars[0]['offset']);
        $this->assertSame(6, $vars[1]['offset']);
    }

    #[Test]
    public function two_hundred_random_token_strings_never_crash_only_parse_error(): void
    {
        $alphabet = [
            '1', '2', '3.5', '.5', '+', '-', '*', '/', '(', ')', ',',
            'min', 'max', 'abs', 'avg', 'price', 'foo', 'bar', '_x', 'x_1',
            ' ', '$', '@', '%', '1.2.3', '--', '()', '..', '(((', ')))',
        ];
        mt_srand(1337);

        for ($i = 0; $i < 200; $i++) {
            $tokenCount = mt_rand(1, 8);
            $expr = '';
            for ($j = 0; $j < $tokenCount; $j++) {
                $expr .= $alphabet[array_rand($alphabet)].' ';
            }

            try {
                $ast = Formula::parse($expr);
                $result = Formula::evaluate($ast, ['avg' => 1, 'price' => 2, 'pi' => M_PI]);
                $this->assertTrue($result === null || is_float($result));
            } catch (FormulaParseError $e) {
                $this->assertGreaterThanOrEqual(0, $e->offset);
                $this->assertLessThanOrEqual(strlen($expr), $e->offset);
            }
        }
    }
}
