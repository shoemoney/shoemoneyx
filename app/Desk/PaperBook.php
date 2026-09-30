<?php

declare(strict_types=1);

namespace App\Desk;

use App\Models\PaperLedger;
use App\Models\Position;
use Illuminate\Support\Facades\DB;

/** Wipes the paper book; shared by the dashboard action and desk:ctl so both behave identically. */
final class PaperBook
{
    public static function reset(): void
    {
        DB::transaction(function () {
            // Children first: MariaDB refuses the parent delete when a cascading child row is locked by another session.
            $ids = Position::mode('paper')->pluck('id');
            DB::table('risk_checks')->whereIn('position_id', $ids)->delete();
            DB::table('fills')->whereIn('position_id', $ids)->update(['position_id' => null]);
            Position::mode('paper')->delete();
            // delete(), not truncate(): TRUNCATE commits implicitly on MariaDB and would break the transaction.
            PaperLedger::query()->delete();
        });
    }
}
