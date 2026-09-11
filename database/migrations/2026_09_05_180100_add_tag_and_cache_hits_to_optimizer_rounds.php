<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optimizer_rounds', function (Blueprint $table) {
            $table->string('tag', 32)->nullable()->index()->after('cash');
            $table->unsignedInteger('cache_hits')->default(0)->after('tag');
        });
    }

    public function down(): void
    {
        Schema::table('optimizer_rounds', function (Blueprint $table) {
            $table->dropColumn(['tag', 'cache_hits']);
        });
    }
};
