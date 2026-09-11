<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No positional clause (`->after(...)`): on this MariaDB, adding a column at a
     * specific position forces a full-table COPY rebuild, which on the 2.5M-row/59GB
     * `backtests` table took 10+ minutes and was killed. Appended at the end, InnoDB
     * does ALGORITHM=INSTANT for the column and an online index build for the FK.
     */
    public function up(): void
    {
        Schema::table('backtests', function (Blueprint $table) {
            $table->foreignId('strategy_plugin_version_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('backtests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('strategy_plugin_version_id');
        });
    }
};
