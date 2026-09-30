<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_intents', function (Blueprint $table) {
            $table->index(['status', 'mode', 'venue', 'id'], 'order_intents_reconciliation_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_intents', function (Blueprint $table) {
            $table->dropIndex('order_intents_reconciliation_index');
        });
    }
};
