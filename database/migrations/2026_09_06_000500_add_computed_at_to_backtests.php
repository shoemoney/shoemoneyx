<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A cache-hit backtest row used to copy stats but stamp its own fresh created_at, so a chain of
 * reused rows could look "just computed" forever even though the underlying simulation is many
 * cache-hour windows stale. computed_at carries the ORIGINAL row's true computation moment through
 * the whole reuse chain (see DeskOptimize::reuseOrQueue); null on a normally-computed row means
 * "same as created_at". Distinct from completed_at, which is "when THIS row's status last went
 * terminal" and does move on a cache-hit clone (it really did just obtain a result, from a cache).
 *
 * computed_at is now added by 2026_09_06_000200 alongside backtests' other lifecycle columns, so
 * they share one ALTER TABLE / one online rebuild on MariaDB instead of two sequential ones. This
 * migration is kept in place (recorded migration histories elsewhere reference it) and reduced to
 * a guarded fallback that only fires if 000200 was somehow skipped or run against an older history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('backtests', 'computed_at')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('backtests', function (Blueprint $table) {
                $table->timestamp('computed_at')->nullable();
            });

            return;
        }

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE backtests ADD COLUMN computed_at TIMESTAMP NULL DEFAULT NULL');
    }

    public function down(): void
    {
        // No-op: 2026_09_06_000200 owns computed_at (added alongside the other lifecycle
        // columns) and drops it in its own down().
    }
};
