<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Execution\Lot;
use Tests\TestCase;

class LotTest extends TestCase
{
    public function test_whole_contract_entry_floors_and_rejects_under_one(): void
    {
        $lot = Lot::forUsd('BTC-USD', 2500, 80000, 0.0002, 0.15, true);   // nano = 0.01 BTC = $800
        $this->assertSame(3, $lot->contracts);
        $this->assertEqualsWithDelta(0.03, $lot->qty, 1e-12);
        $this->assertEqualsWithDelta(2400.0, $lot->notional, 1e-9);
        $this->assertEqualsWithDelta(0.48, $lot->feeUsd, 1e-9, 'notional × taker beats 3 × $0.15');

        $this->assertNull(Lot::forUsd('BTC-USD', 500, 80000, 0.0002, 0.15, true), '$500 is under one $800 contract');
        $this->assertNull(Lot::forUsd('BTC-USD', 0, 80000, 0.0002, 0.15, true));
    }

    public function test_per_contract_floor_bites_on_tiny_tickets(): void
    {
        $lot = Lot::forUsd('XRP-USD', 300, 0.5, 0.0002, 0.15, true);   // 500 XRP = $250 per contract
        $this->assertSame(1, $lot->contracts);
        $this->assertEqualsWithDelta(0.15, $lot->feeUsd, 1e-9, '$250 × 0.02% = $0.05 < one $0.15 contract');
    }

    public function test_fractional_when_whole_contracts_are_off_or_unmapped(): void
    {
        $off = Lot::forUsd('BTC-USD', 500, 80000, 0.0002, 0.15, false);
        $this->assertSame(0, $off->contracts);
        $this->assertEqualsWithDelta(0.00625, $off->qty, 1e-12);

        $unmapped = Lot::forUsd('NOPE-USD', 500, 80000, 0.0002, 0.15, true);
        $this->assertSame(0, $unmapped->contracts);
        $this->assertEqualsWithDelta(500.0, $unmapped->notional, 1e-9);
    }

    /**
     * finding 11 fix: a merely-fractional REQUEST against a normal whole-contract holding (a 1.5- or
     * 2.4-contract trim of a position that itself sits on whole contracts) is not a legacy holding —
     * it floors like any other exit, discarding the sub-contract remainder rather than falling back to
     * a fractional fill. Only $legacyFractional = true (an actual legacy holding) exits at the exact
     * requested quantity. A request under one contract floors to zero and the caller must skip it.
     */
    public function test_exit_floors_whole_contract_requests_and_only_legacy_flag_exits_fractionally(): void
    {
        $this->assertSame(2, Lot::forQty('BTC-USD', 0.024, 80000, 0.0002, 0.15, true)->contracts, '2.4 contracts against a normal holding floors to 2, not a legacy fractional fallback');
        $this->assertSame(2, Lot::forQty('BTC-USD', 0.02, 80000, 0.0002, 0.15, true)->contracts);
        $floored = Lot::forQty('BTC-USD', 0.016, 80000, 0.0002, 0.15, true);
        $this->assertSame(1, $floored->contracts, '1.6 contracts floors to 1 unless explicitly marked legacy');
        $this->assertEqualsWithDelta(0.01, $floored->qty, 1e-12);
        $this->assertSame(1, Lot::forQty('BTC-USD', 0.01, 80000, 0.0002, 0.15, true)->contracts, 'an exact contract multiple stays whole');
        $this->assertSame(3, Lot::forQty('BTC-USD', 0.03, 80000, 0.0002, 0.15, true)->contracts, 'exact multiples are not lost to float noise');

        $dust = Lot::forQty('BTC-USD', 0.003, 80000, 0.0002, 0.15, true);
        $this->assertSame(0, $dust->contracts, 'under one contract floors to zero — the caller must skip, not fill fractionally');
        $this->assertEqualsWithDelta(0.0, $dust->qty, 1e-12);
        $this->assertEqualsWithDelta(0.0, $dust->notional, 1e-12);
        $this->assertSame(0, Lot::forQty('BTC-USD', 0.003, 80000, 0.0002, 0.15, false)->contracts, 'whole contracts off is unaffected — plain fractional path');
    }

    public function test_exit_legacy_flag_exits_fractionally_even_against_a_whole_contract_spec(): void
    {
        // A position opened before whole-contract sizing existed: 1.6 contracts' worth is a genuinely
        // fractional holding, so it must exit at exactly what is held, not floored to 1 contract.
        $legacy = Lot::forQty('BTC-USD', 0.016, 80000, 0.0002, 0.15, true, legacyFractional: true);
        $this->assertSame(0, $legacy->contracts);
        $this->assertEqualsWithDelta(0.016, $legacy->qty, 1e-12);
        $this->assertEqualsWithDelta(0.016 * 80000, $legacy->notional, 1e-9);
    }

    public function test_half_trim_of_three_contracts_sells_exactly_one_contract_floored_quantity(): void
    {
        // 3 contracts of 0.01 BTC = 0.03 BTC; a half-trim requests 0.015 BTC (1.5 contracts) against a
        // normal (non-legacy) holding and must sell exactly 1 contract's worth, never 1.5.
        $half = Lot::forQty('BTC-USD', 0.015, 80000, 0.0002, 0.15, true);
        $this->assertSame(1, $half->contracts);
        $this->assertEqualsWithDelta(0.01, $half->qty, 1e-12);
    }
}
