<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backtests', function (Blueprint $table) {
            $table->index('created_at', 'backtests_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('backtests', function (Blueprint $table) {
            $table->dropIndex('backtests_created_at_index');
        });
    }
};
