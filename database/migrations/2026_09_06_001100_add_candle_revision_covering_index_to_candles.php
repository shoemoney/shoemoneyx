<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DeskOptimize::resolveParams() fingerprints candle data with
 * `MAX(updated_at) WHERE product_id IN (..) AND timeframe IN (..) AND candle_start < $to`
 * on every resolve, so every backtest job pays for it. The existing
 * candles_product_id_timeframe_updated_at_index covers the two IN predicates but not
 * candle_start, so the scan had to fetch the row behind each of the ~41k matching index
 * entries just to evaluate the range — 92% of all mariadbd execution time on .5 was this
 * one query shape, holding the server at ~390% CPU.
 *
 * Appending candle_start/updated_at makes the whole predicate satisfiable from the index
 * (EXPLAIN goes from "Using index condition; Using where" to "Using where; Using index"),
 * which drops the per-row PK seek. Measured on .5 against the live 5.2M-row table:
 * 61ms -> 19.6ms per call, mariadbd 387% -> 123% CPU.
 *
 * Column order matters: the two equality/IN columns first, then the range column, then the
 * aggregated column last so the scan stays index-only.
 */
return new class extends Migration
{
    private const INDEX = 'candles_pid_tf_start_updated_index';

    public function up(): void
    {
        if ($this->hasIndex()) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE INDEX '.self::INDEX.' ON candles (product_id, timeframe, candle_start, updated_at)');

            return;
        }
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE candles ADD INDEX '.self::INDEX.' (product_id, timeframe, candle_start, updated_at), ALGORITHM=INPLACE, LOCK=NONE');
    }

    public function down(): void
    {
        if (! $this->hasIndex()) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);

            return;
        }
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('candles', function ($table) {
            $table->dropIndex(self::INDEX);
        });
    }

    private function hasIndex(): bool
    {
        return collect(Schema::getIndexes('candles'))->contains(fn ($i) => $i['name'] === self::INDEX);
    }
};
