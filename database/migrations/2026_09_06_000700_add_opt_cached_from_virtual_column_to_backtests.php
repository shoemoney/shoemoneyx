<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The retention strip pass must never remove a row's equity_curve/trades while another row still
 * names it as a cache source (params->_opt->cached_from): the clone already has its own copy, but a
 * human tracing lineage from the clone back to the original should not hit a stripped row. Finding
 * that "am I referenced?" without this column meant scanning every row's JSON for a matching
 * cached_from; the virtual column + index make it an index range, same pattern as opt_coin/opt_side/
 * opt_window (2026_09_05_233000). VIRTUAL, no storage cost; MariaDB/MySQL get an inplace index,
 * sqlite (tests) gets the same column via its own generated-column syntax.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            if (! Schema::hasColumn('backtests', 'opt_cached_from')) {
                DB::statement("ALTER TABLE backtests ADD COLUMN opt_cached_from INTEGER GENERATED ALWAYS AS (json_extract(params, '$._opt.cached_from')) VIRTUAL");
            }
            if (! $this->hasIndex('backtests_opt_cached_from_index')) {
                DB::statement('CREATE INDEX backtests_opt_cached_from_index ON backtests (opt_cached_from)');
            }

            return;
        }
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        if (! Schema::hasColumn('backtests', 'opt_cached_from')) {
            DB::statement(<<<'SQL'
                ALTER TABLE backtests
                    ADD COLUMN opt_cached_from BIGINT UNSIGNED GENERATED ALWAYS AS (CAST(JSON_UNQUOTE(JSON_EXTRACT(params, '$._opt.cached_from')) AS UNSIGNED)) VIRTUAL
            SQL);
        }
        if (! $this->hasIndex('backtests_opt_cached_from_index')) {
            DB::statement('ALTER TABLE backtests ADD INDEX backtests_opt_cached_from_index (opt_cached_from), ALGORITHM=INPLACE, LOCK=NONE');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            if ($this->hasIndex('backtests_opt_cached_from_index')) {
                DB::statement('DROP INDEX IF EXISTS backtests_opt_cached_from_index');
            }
            if (Schema::hasColumn('backtests', 'opt_cached_from')) {
                DB::statement('ALTER TABLE backtests DROP COLUMN opt_cached_from');
            }

            return;
        }
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        if ($this->hasIndex('backtests_opt_cached_from_index')) {
            Schema::table('backtests', function ($table) {
                $table->dropIndex('backtests_opt_cached_from_index');
            });
        }
        if (Schema::hasColumn('backtests', 'opt_cached_from')) {
            DB::statement('ALTER TABLE backtests DROP COLUMN opt_cached_from');
        }
    }

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes('backtests'))->contains(fn ($i) => $i['name'] === $name);
    }
};
