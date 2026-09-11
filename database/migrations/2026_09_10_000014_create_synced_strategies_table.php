<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('synced_strategies', function (Blueprint $table) {
            $table->id();
            $table->string('remote_id', 64)->unique();
            $table->string('remote_version', 32);
            $table->string('sha256', 64);
            $table->foreignId('strategy_plugin_id')->nullable()->constrained('strategy_plugins')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            // Hash of the imported StrategyPlugin's definition at the moment we last
            // wrote it from a sync — compared against the plugin's current definition
            // on the next update-import to flag `local_modified` without ever skipping
            // the import (see App\Strategies\Sync\StrategySync::import()).
            $table->string('local_definition_sha256', 64)->nullable();
            $table->timestamp('seen_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('synced_strategies');
    }
};
