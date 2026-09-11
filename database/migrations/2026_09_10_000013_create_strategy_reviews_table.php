<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plugin_id')->constrained('strategy_plugins')->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('strategy_plugin_versions')->cascadeOnDelete();
            $table->foreignId('backtest_id')->constrained('backtests')->cascadeOnDelete();
            $table->string('model');
            $table->string('verdict');
            $table->text('notes')->nullable();
            $table->json('suggestions')->nullable();
            // No updated_at: a review is the agent's verdict on one run, never revised.
            $table->timestamp('created_at');

            $table->index(['plugin_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_reviews');
    }
};
