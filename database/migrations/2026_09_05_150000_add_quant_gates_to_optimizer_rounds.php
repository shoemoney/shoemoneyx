<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optimizer_rounds', function (Blueprint $table) {
            $table->decimal('best_calmar', 10, 2)->nullable()->after('note');
            $table->decimal('best_dsr', 5, 3)->nullable()->after('best_calmar');
            $table->decimal('best_plateau', 5, 3)->nullable()->after('best_dsr');
            $table->string('rank', 8)->default('return')->after('best_plateau');
        });
    }

    public function down(): void
    {
        Schema::table('optimizer_rounds', function (Blueprint $table) {
            $table->dropColumn(['best_calmar', 'best_dsr', 'best_plateau', 'rank']);
        });
    }
};
