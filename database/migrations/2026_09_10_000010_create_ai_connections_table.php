<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_connections', function (Blueprint $table) {
            $table->id();
            // Not a foreign key: with no authentication wired yet the owner is the literal
            // string 'local' (App\Ai\CurrentUser), which no integer users.id can satisfy.
            // Phase 4 starts writing real users.id values here without a schema change.
            $table->string('user_id')->nullable();
            $table->string('provider')->default('openrouter');
            $table->text('key');
            $table->string('label')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('suspended_until')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_connections');
    }
};
