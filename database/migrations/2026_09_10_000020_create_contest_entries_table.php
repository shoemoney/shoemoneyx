<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contest_entries', function (Blueprint $table) {
            $table->id();
            $table->string('contest_slug');
            $table->foreignId('plugin_id')->constrained('strategy_plugins')->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('strategy_plugin_versions')->cascadeOnDelete();
            $table->string('entry_id')->nullable();
            $table->string('paper_account_id')->nullable();
            $table->string('state')->default('live'); // live|withdrawn|settled
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contest_entries');
    }
};
