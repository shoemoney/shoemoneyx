<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** No positional clause: appended at the end so InnoDB does ALGORITHM=INSTANT. */
    public function up(): void
    {
        Schema::table('strategy_plugin_versions', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('strategy_plugin_versions', function (Blueprint $table) {
            $table->dropColumn('published_at');
        });
    }
};
