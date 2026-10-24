<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Strict MariaDB rejects values the SQLite test DB happily stores:
 *  - backtests.opt_coin VARCHAR(16): long ccxt product ids fail the insert (1406).
 *  - candidates.pct_of_free_cash / pct_of_bank decimal(8,4) and optimizer_rounds.best_plateau decimal(5,3)
 *    overflow on a tiny-bank sizing ratio (1264); widened here and clamped at the write sites.
 *  - positions had no index for the per-candidate "closed today" sum filtered on mode+status+closed_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->widenOptCoin(32);

        Schema::table('candidates', function (Blueprint $table) {
            $table->decimal('pct_of_free_cash', 14, 4)->nullable()->change();
            $table->decimal('pct_of_bank', 14, 4)->nullable()->change();
        });
        Schema::table('optimizer_rounds', function (Blueprint $table) {
            $table->decimal('best_plateau', 9, 3)->nullable()->change();
        });
        Schema::table('positions', function (Blueprint $table) {
            $table->index(['mode', 'status', 'closed_at'], 'positions_mode_status_closed_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropIndex('positions_mode_status_closed_at_index');
        });

        // Clamp first so narrowing cannot fail on rows that only fit the wider column.
        DB::table('candidates')->where('pct_of_free_cash', '>', 9999.9999)->update(['pct_of_free_cash' => 9999.9999]);
        DB::table('candidates')->where('pct_of_free_cash', '<', -9999.9999)->update(['pct_of_free_cash' => -9999.9999]);
        DB::table('candidates')->where('pct_of_bank', '>', 9999.9999)->update(['pct_of_bank' => 9999.9999]);
        DB::table('candidates')->where('pct_of_bank', '<', -9999.9999)->update(['pct_of_bank' => -9999.9999]);
        DB::table('optimizer_rounds')->where('best_plateau', '>', 99.999)->update(['best_plateau' => 99.999]);
        DB::table('optimizer_rounds')->where('best_plateau', '<', -99.999)->update(['best_plateau' => -99.999]);

        Schema::table('candidates', function (Blueprint $table) {
            $table->decimal('pct_of_free_cash', 8, 4)->nullable()->change();
            $table->decimal('pct_of_bank', 8, 4)->nullable()->change();
        });
        Schema::table('optimizer_rounds', function (Blueprint $table) {
            $table->decimal('best_plateau', 5, 3)->nullable()->change();
        });

        // A virtual column cannot be clamped; with long product ids already stored, narrowing would fail the whole rollback.
        if (DB::table('backtests')->whereRaw('LENGTH(opt_coin) > 16')->doesntExist()) {
            $this->widenOptCoin(16);
        }
    }

    /** SQLite's generated column is untyped TEXT, so only MariaDB/MySQL carry a width. */
    private function widenOptCoin(int $width): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE backtests MODIFY COLUMN opt_coin VARCHAR({$width}) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(params, '\$._opt.coin'))) VIRTUAL");
    }
};
