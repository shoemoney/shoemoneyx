<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MariaDbColumnWidthsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql_family_connections_pin_the_session_to_utc(): void
    {
        $this->assertSame('+00:00', config('database.connections.mariadb.timezone'));
        $this->assertSame('+00:00', config('database.connections.mysql.timezone'));
    }

    public function test_positions_has_the_mode_status_closed_at_index(): void
    {
        $this->assertTrue(Schema::hasIndex('positions', ['mode', 'status', 'closed_at']));
    }

    public function test_wide_values_fit_the_widened_columns(): void
    {
        $run = DB::table('desk_runs')->insertGetId(['mode' => 'paper', 'strategy' => 'mr', 'started_at' => now()]);
        DB::table('candidates')->insert([
            'desk_run_id' => $run, 'product_id' => 'X-USD', 'rank' => 1, 'rank_reason' => 'x', 'metrics' => '{}',
            'pct_of_free_cash' => 30000, 'pct_of_bank' => 30000,
        ]);
        DB::table('optimizer_rounds')->insert([
            'product_id' => 'X-USD', 'strategy' => 'mr', 'train_from' => now(), 'train_to' => now(), 'test_from' => now(), 'test_to' => now(),
            'candidates' => 1, 'best_plateau' => 250,
        ]);

        $this->assertEquals(30000, DB::table('candidates')->value('pct_of_free_cash'));
        $this->assertEquals(250, DB::table('optimizer_rounds')->value('best_plateau'));
    }

    public function test_opt_coin_virtual_column_holds_a_long_product_id(): void
    {
        $coin = 'BTC/USDT:USDT-PERP-LONG';
        DB::table('backtests')->insert([
            'strategy' => 'mr', 'products' => '[]', 'from' => now(), 'to' => now(), 'starting_cash' => 1,
            'params' => json_encode(['_opt' => ['coin' => $coin, 'side' => 'long', 'window' => '30d']]),
        ]);

        $this->assertSame($coin, DB::table('backtests')->value('opt_coin'));
    }

    public function test_opt_coin_is_32_wide_on_mariadb(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MariaDB only: SQLite generated columns are untyped.');
        }

        $length = DB::scalar("select character_maximum_length from information_schema.columns where table_schema = database() and table_name = 'backtests' and column_name = 'opt_coin'");
        $this->assertSame(32, (int) $length);
    }
}
