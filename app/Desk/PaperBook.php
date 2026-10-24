<?php

declare(strict_types=1);

namespace App\Desk;

use App\Models\PaperLedger;
use App\Models\Position;
use Illuminate\Support\Facades\DB;

/** Wipes the paper book; shared by the dashboard action and desk:ctl so both behave identically. */
final class PaperBook
{
    public const SCOPES = ['main', 'all'];

    /**
     * $scope 'main' wipes only the main desk's own account (arena_seat_id IS NULL); 'all' also wipes every
     * arena seat's positions and ledger (the seats themselves stay and re-seed their starting cash).
     */
    public static function reset(string $scope = 'main'): void
    {
        if (! in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException("paper reset scope must be main or all, got {$scope}");
        }
        $positions = fn () => $scope === 'all' ? Position::mode('paper') : Position::mode('paper')->seat(null);

        DB::transaction(function () use ($scope, $positions) {
            // Children first: MariaDB refuses the parent delete when a cascading child row is locked by another session.
            // A subquery, not a plucked id list: thousands of bound ids would exceed MariaDB's placeholder limit.
            $ids = $positions()->select('id');
            DB::table('risk_checks')->whereIn('position_id', $ids)->delete();
            DB::table('fills')->whereIn('position_id', $ids)->update(['position_id' => null]);
            $positions()->delete();
            // delete(), not truncate(): TRUNCATE commits implicitly on MariaDB and would break the transaction.
            ($scope === 'all' ? PaperLedger::query() : PaperLedger::seat(null))->delete();
        });
    }
}
