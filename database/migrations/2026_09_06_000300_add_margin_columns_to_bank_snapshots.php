<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Code review finding 6: the bank folded a margin position's full notional into equity instead of
 * posted collateral + unrealised PnL. These columns let the snapshot carry the corrected accounting
 * (collateral, unrealised PnL, notional exposure, and live buying power) alongside the existing
 * cash/positions_value/equity fields, which keep their names but now hold the corrected values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_snapshots', function (Blueprint $table) {
            if (! Schema::hasColumn('bank_snapshots', 'collateral')) {
                $table->decimal('collateral', 18, 4)->default(0)->after('positions_value');
            }
            if (! Schema::hasColumn('bank_snapshots', 'unrealised_pnl')) {
                $table->decimal('unrealised_pnl', 18, 4)->default(0)->after('collateral');
            }
            if (! Schema::hasColumn('bank_snapshots', 'exposure')) {
                $table->decimal('exposure', 18, 4)->default(0)->after('unrealised_pnl');
            }
            if (! Schema::hasColumn('bank_snapshots', 'buying_power')) {
                $table->decimal('buying_power', 18, 4)->nullable()->after('exposure');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bank_snapshots', function (Blueprint $table) {
            $drop = array_values(array_filter(
                ['collateral', 'unrealised_pnl', 'exposure', 'buying_power'],
                fn ($c) => Schema::hasColumn('bank_snapshots', $c)
            ));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
