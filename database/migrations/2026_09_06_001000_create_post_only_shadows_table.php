<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per real paper fill (entry/add/trim): the resting post-only limit that fill would
        // have been, and what the sub-minute tape says happened to it. See App\Desk\Execution\PostOnlyShadows.
        Schema::create('post_only_shadows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 8)->default('paper');
            $table->string('product_id', 32);
            $table->string('side', 4);                 // BUY | SELL
            $table->string('kind', 8);                  // entry|add|trim
            $table->decimal('qty', 24, 10);
            $table->decimal('actual_price', 24, 10);
            $table->decimal('actual_fee_usd', 18, 6);
            $table->decimal('limit_price', 24, 10);
            $table->string('status', 16)->default('resting'); // resting|filled|expired
            $table->unsignedTinyInteger('chase_count')->default(0);
            $table->timestamp('placed_at');
            $table->timestamp('expires_at');
            $table->timestamp('resolved_at')->nullable();
            $table->decimal('shadow_price', 24, 10)->nullable();
            $table->decimal('shadow_fee_usd', 18, 6)->nullable();
            $table->decimal('saved_usd', 18, 6)->nullable();
            $table->decimal('miss_move_pct', 10, 4)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('fill_id');
            $table->index('product_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_only_shadows');
    }
};
