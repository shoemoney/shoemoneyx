<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->decimal('realised_usd', 18, 4)->default(0)->after('pnl_pct');   // banked by partial take-profits
            $table->unsignedSmallInteger('trims_count')->default(0)->after('adds_count');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['realised_usd', 'trims_count']);
        });
    }
};
