<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The candle cache's open-window freshness probe needs MAX(updated_at) for (product_id,
 * timeframe) -- the only signal that catches a write which corrects an existing bar's OHLCV
 * without changing candle_start (an UPDATE, not a new row: the feeder does this on every
 * partial-bar flush, and CandleStore::upsert does it on every correction). Without this index
 * that MAX() would fall back to scanning every row for the pair instead of an index range scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasIndex('candles_product_id_timeframe_updated_at_index')) {
            return;
        }
        Schema::table('candles', function (Blueprint $table) {
            $table->index(['product_id', 'timeframe', 'updated_at'], 'candles_product_id_timeframe_updated_at_index');
        });
    }

    public function down(): void
    {
        if (! $this->hasIndex('candles_product_id_timeframe_updated_at_index')) {
            return;
        }
        Schema::table('candles', function (Blueprint $table) {
            $table->dropIndex('candles_product_id_timeframe_updated_at_index');
        });
    }

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes('candles'))->contains(fn ($i) => $i['name'] === $name);
    }
};
