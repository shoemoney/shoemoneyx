<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optimizer_rounds', function (Blueprint $table) {
            $table->string('side', 8)->default('long')->after('strategy');
        });
    }

    public function down(): void
    {
        Schema::table('optimizer_rounds', function (Blueprint $table) {
            $table->dropColumn('side');
        });
    }
};
