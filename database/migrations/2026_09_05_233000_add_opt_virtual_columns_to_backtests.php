<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The arena and optimizer filter backtests by params->_opt->{coin,side,window}. Every row is
 * created the same day (the table is a rolling day), so a created_at filter prunes nothing and
 * each request parsed the JSON of ~800k rows: /api/arena took 20 s. Virtual generated columns
 * over those three keys, indexed, make the same filters an index range. VIRTUAL columns cost
 * no storage; the index is built inplace. MariaDB/MySQL get VIRTUAL columns and an inplace index; sqlite (tests) gets the same columns via its own generated-column syntax.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite's json_extract already returns unquoted scalars; ALTER can add VIRTUAL generated columns.
            foreach (['coin', 'side', 'window'] as $k) {
                DB::statement("ALTER TABLE backtests ADD COLUMN opt_{$k} TEXT GENERATED ALWAYS AS (json_extract(params, '$._opt.{$k}')) VIRTUAL");
            }
            DB::statement('CREATE INDEX backtests_opt_index ON backtests (opt_coin, opt_side, opt_window, status)');

            return;
        }
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        DB::statement(<<<'SQL'
            ALTER TABLE backtests
                ADD COLUMN opt_coin VARCHAR(16) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(params, '$._opt.coin'))) VIRTUAL,
                ADD COLUMN opt_side VARCHAR(8) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(params, '$._opt.side'))) VIRTUAL,
                ADD COLUMN opt_window VARCHAR(8) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(params, '$._opt.window'))) VIRTUAL
        SQL);
        DB::statement('ALTER TABLE backtests ADD INDEX backtests_opt_index (opt_coin, opt_side, opt_window, status), ALGORITHM=INPLACE, LOCK=NONE');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS backtests_opt_index');
            foreach (['coin', 'side', 'window'] as $k) {
                DB::statement("ALTER TABLE backtests DROP COLUMN opt_{$k}");
            }

            return;
        }
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        Schema::table('backtests', function ($table) {
            $table->dropIndex('backtests_opt_index');
        });
        DB::statement('ALTER TABLE backtests DROP COLUMN opt_coin, DROP COLUMN opt_side, DROP COLUMN opt_window');
    }
};
