<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Runtime-editable settings (dashboard). Overrides config('desk.*').
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        // One row per SCAN->VET->SIZE->FILLS cycle.
        Schema::create('desk_runs', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 8);                 // paper | live
            $table->string('strategy', 32);
            $table->string('status', 16)->default('running'); // running|done|halted|error
            $table->boolean('degraded')->default(false);
            $table->unsignedInteger('products_scanned')->default(0);
            $table->unsignedInteger('candidates')->default(0);
            $table->unsignedInteger('passed')->default(0);
            $table->unsignedInteger('rejected')->default(0);
            $table->unsignedInteger('filled')->default(0);
            $table->json('stats')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['mode', 'started_at']);
        });

        // SCAN output
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('desk_run_id')->constrained()->cascadeOnDelete();
            $table->string('product_id', 32);
            $table->unsignedSmallInteger('rank');
            $table->string('rank_reason', 255);
            $table->decimal('score', 12, 6)->default(0);
            $table->json('metrics');                   // ProductStats snapshot
            $table->json('context')->nullable();       // {endpoint, line} | null
            $table->boolean('degraded')->default(false);
            // VET output (denormalised onto the candidate row)
            $table->string('verdict', 16)->nullable(); // PASS|PASS_PARTIAL|REJECT
            $table->string('failed_check', 64)->nullable();
            $table->json('checks_run')->nullable();
            $table->json('checks_skipped')->nullable();
            $table->json('evidence')->nullable();
            $table->text('why')->nullable();
            // SIZE output
            $table->decimal('size_usd', 18, 4)->nullable();
            $table->decimal('pct_of_free_cash', 8, 4)->nullable();
            $table->decimal('pct_of_bank', 8, 4)->nullable();
            $table->boolean('exitable')->nullable();
            $table->boolean('ceiling_applied')->nullable();
            $table->text('size_why')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'created_at']);
            $table->index('verdict');
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 8);
            $table->string('strategy', 32);
            $table->string('product_id', 32);
            $table->string('status', 8)->default('open'); // open|closed
            $table->decimal('quantity', 28, 12)->default(0);
            $table->decimal('entry_price', 24, 10)->nullable();
            $table->decimal('entry_usd', 18, 4)->default(0);   // cost basis incl. fees
            $table->decimal('fees_usd', 18, 6)->default(0);
            $table->decimal('exit_price', 24, 10)->nullable();
            $table->decimal('exit_usd', 18, 4)->nullable();
            $table->decimal('pnl_usd', 18, 4)->nullable();
            $table->decimal('pnl_pct', 10, 4)->nullable();
            $table->decimal('peak_price', 24, 10)->nullable();
            $table->decimal('last_price', 24, 10)->nullable();
            $table->unsignedTinyInteger('adds_count')->default(0);
            $table->string('close_rule', 64)->nullable();
            $table->foreignId('candidate_id')->nullable()->constrained()->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['mode', 'status']);
            $table->index(['product_id', 'status']);
        });

        // FILLS output — one row per executed order (entry, add, exit)
        Schema::create('fills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('desk_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 8);
            $table->string('product_id', 32);
            $table->string('side', 4);                 // BUY | SELL
            $table->string('kind', 8)->default('entry'); // entry|add|exit
            $table->decimal('requested_usd', 18, 4);
            $table->decimal('filled_usd', 18, 4)->default(0);
            $table->decimal('filled_qty', 28, 12)->default(0);
            $table->decimal('decision_price', 24, 10);
            $table->decimal('fill_price', 24, 10)->nullable();
            $table->decimal('slippage_bps', 10, 2)->nullable();
            $table->decimal('fee_usd', 18, 6)->default(0);
            $table->decimal('fee_pct', 8, 5)->default(0);
            $table->boolean('partial')->default(false);
            $table->boolean('whale_hold')->default(false);
            $table->string('status', 16)->default('filled'); // filled|rejected|fee_floor|error
            $table->string('venue_order_id', 64)->nullable();
            $table->json('raw')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['mode', 'created_at']);
            $table->index('product_id');
        });

        // RISK output — every evaluation of an open position
        Schema::create('risk_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            $table->string('action', 8);               // HOLD | CLOSE
            $table->string('rule_fired', 64)->nullable();
            $table->decimal('volume_6h', 28, 8)->nullable();
            $table->decimal('avg_6h', 28, 8)->nullable();
            $table->decimal('ratio', 10, 4)->nullable();
            $table->decimal('price', 24, 10)->nullable();
            $table->decimal('pnl_usd', 18, 4)->nullable();
            $table->decimal('pnl_pct', 10, 4)->nullable();
            $table->unsignedInteger('held_minutes')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['position_id', 'created_at']);
        });

        Schema::create('bank_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 8);
            $table->decimal('cash', 18, 4);
            $table->decimal('positions_value', 18, 4);
            $table->decimal('equity', 18, 4);
            $table->decimal('locked', 18, 4);
            $table->decimal('free_cash', 18, 4);
            $table->decimal('realised_pnl_today', 18, 4)->default(0);
            $table->unsignedSmallInteger('open_positions')->default(0);
            $table->timestamp('taken_at');
            $table->timestamps();

            $table->index(['mode', 'taken_at']);
        });

        // Paper ledger — cash movements in paper mode.
        Schema::create('paper_ledger', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);                // deposit|buy|sell|fee|adjust
            $table->decimal('amount', 18, 6);          // signed
            $table->string('ref', 64)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // CHIEF/agent event log — feeds the dashboard + Telegram.
        Schema::create('desk_events', function (Blueprint $table) {
            $table->id();
            $table->string('level', 8)->default('info'); // info|warn|error|trade
            $table->string('agent', 8);                  // CHIEF|SCAN|VET|SIZE|FILLS|RISK|BOOK
            $table->string('message', 500);
            $table->json('payload')->nullable();
            $table->foreignId('desk_run_id')->nullable();
            $table->timestamps();

            $table->index(['agent', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('backtests', function (Blueprint $table) {
            $table->id();
            $table->string('strategy', 32);
            $table->string('status', 16)->default('running');
            $table->json('products');
            $table->timestamp('from');
            $table->timestamp('to');
            $table->decimal('starting_cash', 18, 4);
            $table->decimal('ending_equity', 18, 4)->nullable();
            $table->json('params')->nullable();
            $table->json('stats')->nullable();
            $table->json('equity_curve')->nullable();
            $table->json('trades')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['backtests', 'desk_events', 'paper_ledger', 'bank_snapshots', 'risk_checks', 'fills', 'positions', 'candidates', 'desk_runs', 'settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
