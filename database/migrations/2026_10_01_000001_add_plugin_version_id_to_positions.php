<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The strategy_plugin_versions row a json-plugin position was opened under, so the risk sweep
     * keeps judging it by that exact definition (its stops, ladder, caps) after the desk's active
     * strategy or plugin changes. NULL for every other strategy and for positions opened before
     * this column existed (those fall back to the active definition, as before).
     */
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->unsignedBigInteger('plugin_version_id')->nullable()->after('strategy');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('plugin_version_id');
        });
    }
};
