<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Data\RiskDecision;
use App\Models\Position;
use App\Models\Product;
use App\Models\RiskCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Round-10 review, BLOCKER: risk_checks.action was string(8) — wide enough for HOLD|CLOSE|TRIM|ADD,
 * not for the 20-char 'force_close_terminal' Desk::runRiskSweep() writes on every terminal
 * force-close sweep. SQLite ignores varchar widths, so 900 tests stayed green while MariaDB
 * (DB_CONNECTION=mysql, strict mode on since 10.2.4) threw SQLSTATE[22001] on every terminal
 * sweep in production. This reads the width straight out of the migrations (the last one to
 * touch the column wins, chronologically by filename) so a future width regression fails here
 * even on sqlite, instead of shipping green and paging in production.
 */
class RiskCheckColumnWidthsTest extends TestCase
{
    use RefreshDatabase;

    /** Reads the effective declared width of one column, following every migration that touches it (in filename/chronological order) — the `up()` body only, so a `down()` rollback width never wins. */
    private function declaredColumnWidth(string $table, string $column): int
    {
        $files = glob(base_path('database/migrations/*.php'));
        sort($files);

        $width = null;
        foreach ($files as $file) {
            $upOnly = explode('function down', file_get_contents($file))[0];
            if (! preg_match_all('/Schema::(?:create|table)\(\s*[\'"]'.preg_quote($table, '/').'[\'"].*?\n\s*\}\);/s', $upOnly, $blocks)) {
                continue;
            }
            foreach ($blocks[0] as $block) {
                if (preg_match('/->string\(\s*[\'"]'.preg_quote($column, '/').'[\'"]\s*,\s*(\d+)\s*\)/', $block, $m)) {
                    $width = (int) $m[1];
                }
            }
        }

        if ($width === null) {
            $this->fail("no declared width found for {$table}.{$column} across database/migrations/*.php");
        }

        return $width;
    }

    public function test_risk_checks_action_column_fits_every_value_the_sweep_can_write(): void
    {
        $width = $this->declaredColumnWidth('risk_checks', 'action');

        // Every value Desk::runRiskSweep()/forceCloseDecision() ever assign to RiskCheck.action:
        // the four RiskDecision action constants, plus the terminal force-close placeholder.
        $writableActions = [
            RiskDecision::HOLD,
            RiskDecision::CLOSE,
            RiskDecision::TRIM,
            RiskDecision::ADD,
            'force_close_terminal',
        ];

        foreach ($writableActions as $action) {
            $this->assertLessThanOrEqual(
                $width,
                strlen($action),
                "risk_checks.action is string({$width}) but '{$action}' is ".strlen($action)." chars"
            );
        }
    }

    public function test_positions_close_rule_column_fits_every_value_the_sweep_can_write(): void
    {
        $width = $this->declaredColumnWidth('positions', 'close_rule');

        $writableCloseRules = [
            'unmeasurable', // Desk::forceCloseDecision()
            'liq_buffer',   // Desk::deleverageIfBufferLow()
            'liquidation',  // Desk::runRiskSweep() margin-liquidation branch
            'risk',         // Desk::close()'s default fallback rule
            'trim',
        ];

        foreach ($writableCloseRules as $rule) {
            $this->assertLessThanOrEqual(
                $width,
                strlen($rule),
                "positions.close_rule is string({$width}) but '{$rule}' is ".strlen($rule)." chars"
            );
        }
    }

    /** End-to-end proof, not just the static width check: a terminal RiskCheck row must actually persist on the real ledger path. */
    public function test_a_terminal_riskcheck_row_persists_with_the_widened_action(): void
    {
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);
        $position = Position::create([
            'mode' => 'paper', 'strategy' => 'probe', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 1.0, 'entry_price' => 100.0, 'entry_usd' => 100.0, 'fees_usd' => 0.0,
            'peak_price' => 100.0, 'last_price' => 105.0, 'opened_at' => now()->subDays(30), 'meta' => [],
        ]);

        $row = RiskCheck::create([
            'position_id' => $position->id,
            'action' => 'force_close_terminal',
            'rule_fired' => null,
            'volume_6h' => null,
            'avg_6h' => null,
            'ratio' => null,
            'price' => 100.0,
            'pnl_usd' => 0.0,
            'pnl_pct' => 0.0,
            'held_minutes' => 0,
            'meta' => [],
        ]);

        $this->assertSame('force_close_terminal', $row->fresh()->action);
    }
}
