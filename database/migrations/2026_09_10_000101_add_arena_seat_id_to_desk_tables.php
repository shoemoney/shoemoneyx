<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Null means "the main desk's own account" — the arena never touches those rows, and every
     * query already filtering on mode/status keeps working unchanged. A seat's own positions,
     * fills, cash movements (paper_ledger) and equity snapshots (bank_snapshots, for scoreboard
     * drawdown) all carry its id instead. Cascading (not nullOnDelete): deleting a seat is only
     * ever done on a retired seat and is meant to erase its history, never fold it into the main
     * desk's own account by turning its rows' arena_seat_id back to null.
     */
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->foreignId('arena_seat_id')->nullable()->after('id')->constrained('arena_seats')->cascadeOnDelete();
            $table->index(['arena_seat_id', 'status']);
        });
        Schema::table('fills', function (Blueprint $table) {
            $table->foreignId('arena_seat_id')->nullable()->after('id')->constrained('arena_seats')->cascadeOnDelete();
            $table->index('arena_seat_id');
        });
        Schema::table('paper_ledger', function (Blueprint $table) {
            $table->foreignId('arena_seat_id')->nullable()->after('id')->constrained('arena_seats')->cascadeOnDelete();
            $table->index('arena_seat_id');
        });
        Schema::table('bank_snapshots', function (Blueprint $table) {
            $table->foreignId('arena_seat_id')->nullable()->after('id')->constrained('arena_seats')->cascadeOnDelete();
            $table->index(['arena_seat_id', 'taken_at']);
        });
    }

    public function down(): void
    {
        // The explicit composite/plain indexes added in up() must go before the column itself —
        // sqlite's ALTER TABLE ... DROP COLUMN chokes on an index still referencing it.
        Schema::table('positions', function (Blueprint $table) {
            $table->dropIndex(['arena_seat_id', 'status']);
            $table->dropConstrainedForeignId('arena_seat_id');
        });
        Schema::table('fills', function (Blueprint $table) {
            $table->dropIndex(['arena_seat_id']);
            $table->dropConstrainedForeignId('arena_seat_id');
        });
        Schema::table('paper_ledger', function (Blueprint $table) {
            $table->dropIndex(['arena_seat_id']);
            $table->dropConstrainedForeignId('arena_seat_id');
        });
        Schema::table('bank_snapshots', function (Blueprint $table) {
            $table->dropIndex(['arena_seat_id', 'taken_at']);
            $table->dropConstrainedForeignId('arena_seat_id');
        });
    }
};
