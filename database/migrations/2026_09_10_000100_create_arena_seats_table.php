<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A seat runs one strategy (a saved plugin version, or a built-in strategy key + its own
        // param overrides) in paper, on its own account, alongside every other active seat. Exactly
        // one seat may be the champion at a time — enforced in ArenaSeatController::promote(), not
        // here (a partial unique index isn't portable to the sqlite the test suite runs on).
        Schema::create('arena_seats', function (Blueprint $table) {
            $table->id();
            $table->string('label', 64);
            $table->foreignId('strategy_plugin_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('strategy_key', 32)->nullable();
            $table->json('params')->nullable();
            $table->decimal('starting_cash', 18, 4)->default(1000);
            $table->string('status', 16)->default('active'); // active|retired
            $table->boolean('is_champion')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('is_champion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arena_seats');
    }
};
