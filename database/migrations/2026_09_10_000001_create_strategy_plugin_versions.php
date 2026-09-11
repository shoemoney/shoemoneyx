<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_plugin_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_plugin_id')->constrained('strategy_plugins')->cascadeOnDelete();
            $table->string('version', 32);
            $table->json('definition');
            $table->text('changelog')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->unique(['strategy_plugin_id', 'version']);
        });

        // No ->after(): a positional column clause forces a full-table COPY rebuild
        // on this MariaDB, however small the table — append-only stays ALGORITHM=INSTANT.
        Schema::table('strategy_plugins', function (Blueprint $table) {
            $table->string('current_version', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('strategy_plugins', function (Blueprint $table) {
            $table->dropColumn('current_version');
        });
        Schema::dropIfExists('strategy_plugin_versions');
    }
};
