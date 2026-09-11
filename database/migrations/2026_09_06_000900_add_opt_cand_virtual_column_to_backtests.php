<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The optimizer's retention pass needs to protect champion rows from the sweep by their candidate
 * id (params->_opt->cand) without parsing JSON per row. Same pattern as opt_coin/opt_side/opt_window
 * (2026_09_05_233000) and opt_cached_from (2026_09_06_000700): an indexed VIRTUAL column turns the
 * lookup into an index range instead of a full scan. VIRTUAL, no storage cost; MariaDB/MySQL get an
 * inplace index, sqlite (tests) gets the same column via its own generated-column syntax.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            if (! Schema::hasColumn('backtests', 'opt_cand')) {
                DB::statement("ALTER TABLE backtests ADD COLUMN opt_cand INTEGER GENERATED ALWAYS AS (json_extract(params, '$._opt.cand')) VIRTUAL");
            }
            if (! $this->hasIndex('backtests_opt_cand_index')) {
                DB::statement('CREATE INDEX backtests_opt_cand_index ON backtests (opt_cand)');
            }

            return;
        }
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        if (! Schema::hasColumn('backtests', 'opt_cand')) {
            DB::statement(<<<'SQL'
                ALTER TABLE backtests
                    ADD COLUMN opt_cand INT GENERATED ALWAYS AS (CAST(JSON_UNQUOTE(JSON_EXTRACT(params, '$._opt.cand')) AS SIGNED)) VIRTUAL
            SQL);
        }
        if (! $this->hasIndex('backtests_opt_cand_index')) {
            DB::statement('ALTER TABLE backtests ADD INDEX backtests_opt_cand_index (opt_cand), ALGORITHM=INPLACE, LOCK=NONE');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            if ($this->hasIndex('backtests_opt_cand_index')) {
                DB::statement('DROP INDEX IF EXISTS backtests_opt_cand_index');
            }
            if (Schema::hasColumn('backtests', 'opt_cand')) {
                DB::statement('ALTER TABLE backtests DROP COLUMN opt_cand');
            }

            return;
        }
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        if ($this->hasIndex('backtests_opt_cand_index')) {
            Schema::table('backtests', function ($table) {
                $table->dropIndex('backtests_opt_cand_index');
            });
        }
        if (Schema::hasColumn('backtests', 'opt_cand')) {
            DB::statement('ALTER TABLE backtests DROP COLUMN opt_cand');
        }
    }

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes('backtests'))->contains(fn ($i) => $i['name'] === $name);
    }
};
