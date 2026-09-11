<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('optimizer_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('product_id', 24)->index();
            $table->string('strategy', 32);
            $table->timestamp('train_from');
            $table->timestamp('train_to');
            $table->timestamp('test_from');
            $table->timestamp('test_to');
            $table->unsignedInteger('candidates');
            $table->json('champion_params')->nullable();
            $table->decimal('champion_train', 8, 2)->nullable();
            $table->decimal('champion_test', 8, 2)->nullable();
            $table->json('best_params')->nullable();
            $table->decimal('best_train', 8, 2)->nullable();
            $table->decimal('best_test', 8, 2)->nullable();
            $table->boolean('promoted')->default(false);
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optimizer_rounds');
    }
};
