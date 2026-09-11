<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backtests', function (Blueprint $table) {
            $table->index(['status', 'updated_at'], 'backtests_status_updated_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('backtests', function (Blueprint $table) {
            $table->dropIndex('backtests_status_updated_at_index');
        });
    }
};
