<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('product_id', 32)->unique();      // BTC-USD
            $table->string('base_currency', 16);
            $table->string('quote_currency', 16);
            $table->string('status', 24)->default('online');
            $table->decimal('price', 24, 10)->nullable();
            $table->decimal('price_change_24h_pct', 12, 4)->nullable();
            $table->decimal('volume_24h', 28, 8)->nullable();      // base units
            $table->decimal('volume_24h_usd', 24, 4)->nullable();
            $table->decimal('base_increment', 24, 12)->nullable();
            $table->decimal('quote_increment', 24, 12)->nullable();
            $table->decimal('quote_min_size', 24, 8)->nullable();
            $table->decimal('base_min_size', 24, 12)->nullable();
            $table->boolean('is_tracked')->default(false);      // pull candles + chartable
            $table->boolean('trading_disabled')->default(false);
            $table->timestamp('listed_at')->nullable();         // first time we saw it
            $table->timestamp('synced_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['quote_currency', 'status']);
            $table->index('is_tracked');
        });

        Schema::create('candles', function (Blueprint $table) {
            $table->id();
            $table->string('product_id', 32);
            $table->string('timeframe', 4);                     // 1m 5m 15m 30m 1H 6H 1D
            $table->timestamp('candle_start');
            $table->decimal('open', 24, 10);
            $table->decimal('high', 24, 10);
            $table->decimal('low', 24, 10);
            $table->decimal('close', 24, 10);
            $table->decimal('volume', 28, 8);                   // base units
            $table->timestamps();

            $table->unique(['product_id', 'timeframe', 'candle_start']);
            $table->index(['product_id', 'timeframe', 'candle_start']);
        });

        Schema::create('coinbase_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Default');
            $table->text('api_key_name');
            $table->text('api_private_key');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('coinbase_api_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coinbase_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 8);
            $table->string('endpoint');
            $table->json('request_body')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coinbase_api_logs');
        Schema::dropIfExists('coinbase_accounts');
        Schema::dropIfExists('candles');
        Schema::dropIfExists('products');
    }
};
