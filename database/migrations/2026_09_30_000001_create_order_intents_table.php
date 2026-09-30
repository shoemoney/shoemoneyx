<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per LIVE order the desk is about to send, written BEFORE the request leaves the box.
     * It carries the client_order_id the venue dedups on and stays 'pending' until the venue's own
     * order record proves the outcome, so a timeout or a failed readback can never turn into a second
     * order (or an unrecorded one). Paper executors never touch it.
     */
    public function up(): void
    {
        Schema::create('order_intents', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 8);
            $table->string('venue', 40);                           // which executor sent it: spot and perps are both mode 'live'
            $table->string('desk_product_id', 32);                 // what the desk calls it (BTC-USD)
            $table->string('venue_product_id', 32);                // what the order went to (BIP-20DEC30-CDE on perps)
            $table->string('method', 12);                          // buy|sell|open_short|cover_short
            $table->string('side', 4);                             // BUY | SELL
            $table->string('client_order_id', 64)->unique();
            $table->string('venue_order_id', 64)->nullable();
            $table->string('status', 10)->default('pending');      // pending|resolved
            $table->string('outcome', 12)->nullable();             // filled|rejected|abandoned
            $table->decimal('requested_usd', 18, 4)->default(0);
            $table->decimal('decision_price', 24, 10)->default(0);
            $table->json('context')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('sent_at')->nullable();              // last time a send was attempted; the grace window runs from here
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['mode', 'venue', 'desk_product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_intents');
    }
};
