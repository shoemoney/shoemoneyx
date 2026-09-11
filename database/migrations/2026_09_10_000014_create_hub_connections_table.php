<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_connections', function (Blueprint $table) {
            $table->id();
            $table->string('user_handle')->nullable();
            $table->string('desk_id')->nullable();
            $table->text('token');
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_connections');
    }
};
