<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pins each position to the venue that opened it (the same ids order_intents.venue carries:
     * coinbase_spot, coinbase_perps, ccxt:<exchange>, plus 'paper'). Spot and perps are both mode
     * 'live' and share desk product ids, so a position opened on one venue must never be closed
     * through the other after a config flip.
     *
     * Existing rows are backfilled only where the evidence is unambiguous: every filled opening
     * order intent for that product names one venue and the position's shape does not contradict it,
     * else, with no intent evidence at all, a short (spot cannot short) or a recorded perps margin
     * means perps. Anything else stays NULL, which the desk treats as "matches the current
     * executor" and logs a warning each time it acts on one.
     */
    public function up(): void
    {
        // MariaDB DDL is not transactional: if the backfill below dies after the column exists, a
        // retry must not fail on "duplicate column". The backfill only touches NULL rows, so it is
        // safe to run again over a partially filled table.
        if (! Schema::hasColumn('positions', 'venue')) {
            Schema::table('positions', function (Blueprint $table) {
                $table->string('venue', 40)->nullable()->after('mode');
            });
        }

        DB::table('positions')->where('mode', 'paper')->update(['venue' => 'paper']);

        // eachById pages by primary key; each() pages by offset and would skip rows as the update
        // removes them from the whereNull('venue') result set.
        DB::table('positions')->where('mode', 'live')->whereNull('venue')->eachById(function ($p) {
            $meta = json_decode((string) ($p->meta ?? ''), true);
            $perpsHint = $p->side === 'short' || (is_array($meta) && isset($meta['margin_usd']));
            // Only a CONFIRMED opening order is evidence: a rejected or abandoned intent opened nothing,
            // and a sell/cover says nothing about where the position was opened. The product match is
            // done by the database so it follows the column collation (utf8mb4_unicode_ci treats
            // BTC-USD and btc-usd as one product); grouping in PHP would split them.
            $seen = DB::table('order_intents')
                ->where('mode', 'live')
                ->where('outcome', 'filled')
                ->whereIn('method', ['buy', 'open_short'])
                ->where('desk_product_id', $p->product_id)
                ->distinct()
                ->pluck('venue');

            $venue = null;
            if ($seen->count() === 1) {
                $venue = $seen->first();
                if ($perpsHint && $venue !== 'coinbase_perps') {
                    $venue = null;   // the position's own shape contradicts the product-level intent evidence
                }
            } elseif ($seen->isEmpty() && $perpsHint) {
                $venue = 'coinbase_perps';
            }

            if ($venue !== null) {
                DB::table('positions')->where('id', $p->id)->update(['venue' => $venue]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('venue');
        });
    }
};
