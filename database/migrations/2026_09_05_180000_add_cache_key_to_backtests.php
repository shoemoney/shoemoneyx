<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backtests', function (Blueprint $table) {
            $table->string('cache_key', 64)->nullable()->index()->after('params');
        });
    }

    public function down(): void
    {
        Schema::table('backtests', function (Blueprint $table) {
            $table->dropColumn('cache_key');
        });
    }
};
