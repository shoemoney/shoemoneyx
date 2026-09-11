<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_calls', function (Blueprint $table) {
            $table->id();
            // Same shape as ai_connections.user_id, and for the same reason: the owner
            // key is a string ('local' until Phase 4), not an integer users.id.
            $table->string('user_id')->nullable();
            $table->string('model');
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->string('status');
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            // No updated_at: an audit row is written once and never touched again.
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_calls');
    }
};
