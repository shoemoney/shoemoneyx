<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** No positional clause: appended at the end so InnoDB does ALGORITHM=INSTANT. */
    public function up(): void
    {
        Schema::table('strategy_plugins', function (Blueprint $table) {
            $table->boolean('auto_backtest')->nullable()->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('strategy_plugins', function (Blueprint $table) {
            $table->dropColumn('auto_backtest');
        });
    }
};
