<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeskRunSeatMigrationTest extends TestCase
{
    protected bool $deskPasswordSet = false;

    public function test_backfill_requires_one_proven_seat_and_migration_can_be_reversed(): void
    {
        // DDL is isolated from RefreshDatabase's transaction and the configured test database.
        // The regular database suite separately applies this migration on its configured engine.
        $default = DB::getDefaultConnection();
        config(['database.connections.arena_scope_migration' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('arena_scope_migration');
        Schema::clearResolvedInstance('db.schema');

        try {
            Schema::create('arena_seats', fn (Blueprint $table) => $table->id());
            Schema::create('desk_runs', function (Blueprint $table) {
                $table->id();
                $table->string('mode')->default('paper');
                $table->timestamp('started_at')->nullable();
            });
            Schema::create('fills', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('desk_run_id')->nullable();
                $table->unsignedBigInteger('arena_seat_id')->nullable();
            });
            DB::table('arena_seats')->insert([['id' => 1], ['id' => 2]]);
            DB::table('desk_runs')->insert(array_map(fn ($id) => ['id' => $id], range(1, 5)));
            DB::table('fills')->insert([
                ['desk_run_id' => 1, 'arena_seat_id' => 1],
                ['desk_run_id' => 1, 'arena_seat_id' => 1],
                ['desk_run_id' => 2, 'arena_seat_id' => null],
                ['desk_run_id' => 3, 'arena_seat_id' => 1],
                ['desk_run_id' => 3, 'arena_seat_id' => null],
                ['desk_run_id' => 4, 'arena_seat_id' => 1],
                ['desk_run_id' => 4, 'arena_seat_id' => 2],
            ]);

            $migration = require database_path('migrations/2026_10_23_000001_add_arena_seat_id_to_desk_runs.php');
            // Simulate a MariaDB deployment stopping just after its first committed DDL.
            Schema::table('desk_runs', fn (Blueprint $table) => $table->foreignId('arena_seat_id')->nullable());
            $migration->up();
            $this->assertSame([1, null, null, null, null], DB::table('desk_runs')->orderBy('id')->pluck('arena_seat_id')->all());
            $migration->up();
            $this->assertSame([1, null, null, null, null], DB::table('desk_runs')->orderBy('id')->pluck('arena_seat_id')->all(), 'retry after a partial backfill is idempotent');
            $migration->down();
            $this->assertFalse(Schema::hasColumn('desk_runs', 'arena_seat_id'));
            $this->assertSame(5, DB::table('desk_runs')->count());
            $migration->up();
            DB::table('arena_seats')->where('id', 1)->delete();
            $this->assertFalse(DB::table('desk_runs')->where('id', 1)->exists(), 'deleting a seat never reattributes its known run to the main desk');
            $this->assertSame(4, DB::table('desk_runs')->count());
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('arena_scope_migration');
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
