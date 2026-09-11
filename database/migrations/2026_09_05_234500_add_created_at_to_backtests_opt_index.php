<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The arena's "challengers today" count filters on created_at after the opt_* keys. Without
 * created_at in the index MariaDB visits each of the ~66k matching rows to read it (0.79 s);
 * with it the count is index-only (tens of ms).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS backtests_opt_index');
            DB::statement('CREATE INDEX backtests_opt_index ON backtests (opt_coin, opt_side, opt_window, status, created_at)');

            return;
        }
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE backtests DROP INDEX backtests_opt_index, ADD INDEX backtests_opt_index (opt_coin, opt_side, opt_window, status, created_at), ALGORITHM=INPLACE, LOCK=NONE');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS backtests_opt_index');
            DB::statement('CREATE INDEX backtests_opt_index ON backtests (opt_coin, opt_side, opt_window, status)');

            return;
        }
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE backtests DROP INDEX backtests_opt_index, ADD INDEX backtests_opt_index (opt_coin, opt_side, opt_window, status), ALGORITHM=INPLACE, LOCK=NONE');
        }
    }
};
