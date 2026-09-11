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
            $table->unsignedTinyInteger('folds')->default(1)->after('rank');
            $table->decimal('best_test_min', 8, 2)->nullable()->after('folds');
        });
    }

    public function down(): void
    {
        Schema::table('optimizer_rounds', function (Blueprint $table) {
            $table->dropColumn(['folds', 'best_test_min']);
        });
    }
};
