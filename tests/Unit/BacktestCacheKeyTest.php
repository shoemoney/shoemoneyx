<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Backtest;
use Tests\TestCase;

class BacktestCacheKeyTest extends TestCase
{
    public function test_param_key_order_does_not_change_the_key(): void
    {
        $a = Backtest::cacheKeyFor('mr', ['BTC-USD'], '2026-08-01', '2026-08-24', 10000.0, ['mr.timeframe' => '2m', 'mr.leverage' => 3]);
        $b = Backtest::cacheKeyFor('mr', ['BTC-USD'], '2026-08-01', '2026-08-24', 10000.0, ['mr.leverage' => 3, 'mr.timeframe' => '2m']);

        $this->assertSame($a, $b);
    }

    public function test_opt_differences_do_not_change_the_key(): void
    {
        $a = Backtest::cacheKeyFor('mr', ['BTC-USD'], '2026-08-01', '2026-08-24', 10000.0, ['mr.timeframe' => '2m', '_opt' => ['cand' => 1, 'batch' => 'b1']]);
        $b = Backtest::cacheKeyFor('mr', ['BTC-USD'], '2026-08-01', '2026-08-24', 10000.0, ['mr.timeframe' => '2m', '_opt' => ['cand' => 99, 'batch' => 'b2', 'tag' => 'x']]);

        $this->assertSame($a, $b, '_opt is per-candidate bookkeeping, not part of the strategy identity');
    }

    public function test_different_to_changes_the_key(): void
    {
        $a = Backtest::cacheKeyFor('mr', ['BTC-USD'], '2026-08-01', '2026-08-24', 10000.0, ['mr.timeframe' => '2m']);
        $b = Backtest::cacheKeyFor('mr', ['BTC-USD'], '2026-08-01', '2026-08-25', 10000.0, ['mr.timeframe' => '2m']);

        $this->assertNotSame($a, $b);
    }

    public function test_different_starting_cash_changes_the_key(): void
    {
        $a = Backtest::cacheKeyFor('mr', ['BTC-USD'], '2026-08-01', '2026-08-24', 10000.0, ['mr.timeframe' => '2m']);
        $b = Backtest::cacheKeyFor('mr', ['BTC-USD'], '2026-08-01', '2026-08-24', 3000.0, ['mr.timeframe' => '2m']);

        $this->assertNotSame($a, $b);
    }
}
