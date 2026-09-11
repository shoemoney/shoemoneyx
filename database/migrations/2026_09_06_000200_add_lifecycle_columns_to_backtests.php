<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lifecycle columns the farm dashboard and retention can filter on without reading JSON:
 *  - completed_at: set once, on the successful terminal transition (updated_at also moves when
 *    retention strips payloads, so it cannot mean "finished").
 *  - payload_stripped_at: set when curve/trades are removed, so the strip job walks an index
 *    instead of re-evaluating JSON predicates over already-stripped history.
 *  - under_one_contract: the stats.under_one_contract counter as a scalar for SUM() in SQL.
 *  - computed_at: carries a cache-hit clone's ORIGINAL computation moment through the reuse
 *    chain (2026_09_06_000500 used to add this separately; it is folded in here — see that
 *    file for why).
 *
 * `backtests` is 7.6 GiB and already carries indexed VIRTUAL columns, so on MariaDB adding a
 * column here is neither INSTANT nor NOCOPY (ER 1845/1846) -- it is a full online rebuild.
 * All four columns are therefore added in ONE ALTER TABLE (one rebuild, not four), and both
 * indexes in a second ALTER, both ALGORITHM=INPLACE, LOCK=NONE. sqlite (tests) keeps Blueprint
 * per-column since a full-table rebuild there is free.
 */
return new class extends Migration
{
    /** @var array<string, string> column name => mysql/mariadb column definition */
    private const MYSQL_COLUMNS = [
        'completed_at' => 'completed_at TIMESTAMP NULL DEFAULT NULL',
        'payload_stripped_at' => 'payload_stripped_at TIMESTAMP NULL DEFAULT NULL',
        'under_one_contract' => 'under_one_contract INT UNSIGNED NOT NULL DEFAULT 0',
        'computed_at' => 'computed_at TIMESTAMP NULL DEFAULT NULL',
    ];

    /** @var array<string, string> index name => column list */
    private const INDEXES = [
        'backtests_status_completed_at_index' => 'status, completed_at',
        'backtests_payload_stripped_at_index' => 'payload_stripped_at, created_at',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->upSqlite();

            return;
        }

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $adds = [];
        foreach (self::MYSQL_COLUMNS as $name => $definition) {
            if (! Schema::hasColumn('backtests', $name)) {
                $adds[] = 'ADD COLUMN '.$definition;
            }
        }
        if ($adds !== []) {
            DB::statement('ALTER TABLE backtests '.implode(', ', $adds));
        }

        $indexAdds = [];
        foreach (self::INDEXES as $name => $columns) {
            if (! $this->hasIndex($name)) {
                $indexAdds[] = "ADD INDEX {$name} ({$columns})";
            }
        }
        if ($indexAdds !== []) {
            DB::statement('ALTER TABLE backtests '.implode(', ', $indexAdds).', ALGORITHM=INPLACE, LOCK=NONE');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->downSqlite();

            return;
        }

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $indexDrops = [];
        foreach (array_keys(self::INDEXES) as $name) {
            if ($this->hasIndex($name)) {
                $indexDrops[] = "DROP INDEX {$name}";
            }
        }
        if ($indexDrops !== []) {
            DB::statement('ALTER TABLE backtests '.implode(', ', $indexDrops).', ALGORITHM=INPLACE, LOCK=NONE');
        }

        $drops = [];
        foreach (array_keys(self::MYSQL_COLUMNS) as $name) {
            if (Schema::hasColumn('backtests', $name)) {
                $drops[] = "DROP COLUMN {$name}";
            }
        }
        if ($drops !== []) {
            DB::statement('ALTER TABLE backtests '.implode(', ', $drops));
        }
    }

    private function upSqlite(): void
    {
        Schema::table('backtests', function (Blueprint $table) {
            if (! Schema::hasColumn('backtests', 'completed_at')) {
                $table->timestamp('completed_at')->nullable();
            }
            if (! Schema::hasColumn('backtests', 'payload_stripped_at')) {
                $table->timestamp('payload_stripped_at')->nullable();
            }
            if (! Schema::hasColumn('backtests', 'under_one_contract')) {
                $table->unsignedInteger('under_one_contract')->default(0);
            }
            if (! Schema::hasColumn('backtests', 'computed_at')) {
                $table->timestamp('computed_at')->nullable();
            }
        });

        foreach (self::INDEXES as $name => $columns) {
            if ($this->hasIndex($name)) {
                continue;
            }
            $cols = implode(', ', array_map('trim', explode(',', $columns)));
            DB::statement("CREATE INDEX {$name} ON backtests ({$cols})");
        }
    }

    private function downSqlite(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            if ($this->hasIndex($name)) {
                DB::statement("DROP INDEX {$name}");
            }
        }

        Schema::table('backtests', function (Blueprint $table) {
            foreach (array_keys(self::MYSQL_COLUMNS) as $name) {
                if (Schema::hasColumn('backtests', $name)) {
                    $table->dropColumn($name);
                }
            }
        });
    }

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes('backtests'))->contains(fn ($i) => $i['name'] === $name);
    }
};
