<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * holdout_return: the winning candidate's return on an untouched holdout window (--holdout), scored
 * once per round AFTER selection and never used to rank candidates. Null when --holdout=0 or nothing
 * was about to be promoted.
 * trials_cumulative: this round's own qualifying-candidate count PLUS every trial ever counted for
 * the same (strategy, coin, side, tag) lineage, so the deflated-Sharpe correction accounts for the
 * whole search history a coin has been through, not just one round's sample.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optimizer_rounds', function (Blueprint $table) {
            if (! Schema::hasColumn('optimizer_rounds', 'holdout_return')) {
                $table->decimal('holdout_return', 10, 4)->nullable()->after('best_folds_complete');
            }
            if (! Schema::hasColumn('optimizer_rounds', 'trials_cumulative')) {
                $table->unsignedInteger('trials_cumulative')->nullable()->after('holdout_return');
            }
        });
    }

    public function down(): void
    {
        Schema::table('optimizer_rounds', function (Blueprint $table) {
            $drop = array_values(array_filter(
                ['holdout_return', 'trials_cumulative'],
                fn ($c) => Schema::hasColumn('optimizer_rounds', $c)
            ));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
