<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candles', function (Blueprint $table) {
            $table->index(['timeframe', 'candle_start'], 'candles_timeframe_candle_start_index');
        });
    }

    public function down(): void
    {
        Schema::table('candles', function (Blueprint $table) {
            $table->dropIndex('candles_timeframe_candle_start_index');
        });
    }
};
