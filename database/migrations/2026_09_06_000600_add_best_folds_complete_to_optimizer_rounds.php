<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many of the `folds` requested test windows the best-on-train candidate actually completed —
 * preserved explicitly so a round that refused (or, before this fix, wrongly agreed) to promote on
 * partial fold data is auditable after the fact instead of only inferable from the mean it shows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('optimizer_rounds', 'best_folds_complete')) {
            return;
        }
        Schema::table('optimizer_rounds', function (Blueprint $table) {
            $table->unsignedTinyInteger('best_folds_complete')->nullable()->after('best_test_min');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('optimizer_rounds', 'best_folds_complete')) {
            return;
        }
        Schema::table('optimizer_rounds', function (Blueprint $table) {
            $table->dropColumn('best_folds_complete');
        });
    }
};
