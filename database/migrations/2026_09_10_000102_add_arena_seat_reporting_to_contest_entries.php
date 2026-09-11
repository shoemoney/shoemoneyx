<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paper trading stays local (docs/HUB_API.md): a contest entry runs in its own arena seat and
     * ContestReporter pushes fills/snapshots to the hub instead of routing orders through it.
     * `entry_id`/`paper_account_id` (from the earlier design) are left in place unused rather than
     * dropped — `paper_account_id` in particular is no longer populated by HubContestController.
     */
    public function up(): void
    {
        Schema::table('contest_entries', function (Blueprint $table) {
            $table->foreignId('arena_seat_id')->nullable()->constrained('arena_seats')->nullOnDelete();
            $table->unsignedBigInteger('last_reported_fill_id')->nullable();
            $table->timestamp('last_snapshot_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contest_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('arena_seat_id');
            $table->dropColumn(['last_reported_fill_id', 'last_snapshot_at']);
        });
    }
};
