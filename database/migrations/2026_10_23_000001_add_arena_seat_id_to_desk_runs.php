<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MariaDB commits each DDL statement independently. Resume safely if deployment
        // stops between the column, constraint, index or data-backfill steps.
        if (! Schema::hasColumn('desk_runs', 'arena_seat_id')) {
            Schema::table('desk_runs', fn (Blueprint $table) => $table->foreignId('arena_seat_id')->nullable());
        }
        $hasSeatForeignKey = collect(Schema::getForeignKeys('desk_runs'))
            ->contains(fn ($foreign) => $foreign['columns'] === ['arena_seat_id']);
        if (! $hasSeatForeignKey) {
            Schema::table('desk_runs', fn (Blueprint $table) => $table->foreign('arena_seat_id')->references('id')->on('arena_seats')->cascadeOnDelete());
        }
        if (! Schema::hasIndex('desk_runs', ['arena_seat_id', 'mode', 'started_at'])) {
            Schema::table('desk_runs', fn (Blueprint $table) => $table->index(['arena_seat_id', 'mode', 'started_at']));
        }

        // Historical runs did not record their owner. Only recover a seat when every linked
        // fill belongs to that single seat. Mixed runs and runs without fills cannot be inferred
        // safely; they retain the legacy null attribution rather than guessing from strategy names.
        DB::table('desk_runs')->select('id')->orderBy('id')->chunkById(500, function ($runs) {
            $fills = DB::table('fills')->whereIn('desk_run_id', $runs->pluck('id'))
                ->select('desk_run_id', 'arena_seat_id')->distinct()->get()->groupBy('desk_run_id');
            foreach ($fills as $runId => $owners) {
                if ($owners->count() === 1 && $owners->first()->arena_seat_id !== null) {
                    DB::table('desk_runs')->where('id', $runId)->update(['arena_seat_id' => $owners->first()->arena_seat_id]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('desk_runs', function (Blueprint $table) {
            $table->dropIndex(['arena_seat_id', 'mode', 'started_at']);
            $table->dropConstrainedForeignId('arena_seat_id');
        });
    }
};
