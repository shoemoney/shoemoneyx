<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * create_market_tables added both a unique and a plain index on (product_id, timeframe, candle_start).
 * The unique one enforces the upsert identity and serves every range read; the plain one is a
 * second B-tree maintained on every candle write for nothing. Drop only the plain one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->hasIndex('candles_product_id_timeframe_candle_start_index')) {
            return;
        }
        Schema::table('candles', function ($table) {
            $table->dropIndex('candles_product_id_timeframe_candle_start_index');
        });
    }

    public function down(): void
    {
        if ($this->hasIndex('candles_product_id_timeframe_candle_start_index')) {
            return;
        }
        Schema::table('candles', function ($table) {
            $table->index(['product_id', 'timeframe', 'candle_start'], 'candles_product_id_timeframe_candle_start_index');
        });
    }

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes('candles'))->contains(fn ($i) => $i['name'] === $name);
    }
};
