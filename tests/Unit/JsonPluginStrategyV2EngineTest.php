<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Data\ProductStats;
use App\Desk\Data\RiskDecision;
use App\Desk\DeskContext;
use App\Desk\Strategies\JsonPluginStrategy;
use App\Desk\Strategies\JsonRuleEvaluator;
use App\Models\Position;
use App\Models\StrategyPlugin;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase C engine (docs/STRATEGY_SCHEMA_V2.md): JsonPluginStrategy::risk() driving the shipped
 * SMX π Take Profit v2 example (resources/strategies/examples/smx-pi-take-profit-v2.json)
 * through a hand-built price path, canned-tape style (see JsonRunnerRiskTest.php) — risk() is
 * called directly and each RiskDecision is applied to the same Position object the way
 * Desk::trim()/Desk::addFromRisk() would, so state (avg, ladder, reentries) threads through the
 * whole sequence exactly as it would in a real run. entry.when's signals (liquid, momentum,
 * not_overbought) are satisfied by fixed ProductStats fields on every step except `price`, so
 * every assertion below isolates RISK's own ladder/reentry/stop/runner mechanics.
 *
 * Fees are zeroed for the ladder/reentry/rearm/runner sequence — those assertions are about
 * quantities and state transitions, not fee arithmetic, which greenAfterFees() and the
 * cash_out unit tests below cover directly with a real rate.
 */
class JsonPluginStrategyV2EngineTest extends TestCase
{
    use RefreshDatabase;

    private function plugin(string $key, array $overrides = []): array
    {
        $def = json_decode(file_get_contents(base_path('resources/strategies/examples/smx-pi-take-profit-v2.json')), true);
        $def['key'] = $key;
        $def = array_replace_recursive($def, $overrides);
        StrategyPlugin::create(['key' => $key, 'name' => $def['meta']['name'], 'definition' => $def]);

        return $def;
    }

    /** A fixed, mildly oscillating 20-bar tape (RSI(14) settles around 47 — comfortably under not_overbought's 70), shared by every step so `ind.rsi(14)` never depends on the walk-up price path. */
    private function ctx(string $key, float $takerRate = 0.0): DeskContext
    {
        $bars = [];
        $price = 100.0;
        $ts = 1_700_000_000;
        for ($i = 0; $i < 20; $i++) {
            $price *= 1 + ($i % 2 === 0 ? 0.003 : -0.003);
            $bars[] = ['start' => $ts, 'open' => $price, 'high' => $price, 'low' => $price, 'close' => $price, 'volume' => 10];
            $ts += 3600;
        }
        $provider = fn (string $pid, string $tf, int $from, ?int $to): array => $bars;

        return new DeskContext(
            ['json' => ['plugin_key' => $key], 'fees' => ['taker_rate' => $takerRate]],
            'paper', false, [], new \DateTimeImmutable('@'.($ts + 3600)), false, $provider,
        );
    }

    /** liquid/momentum/not_overbought all hold regardless of $price — every scenario below is isolating RISK, not SCAN. */
    private function stats(float $price): ProductStats
    {
        return ProductStats::fromArray([
            'product_id' => 'BTC-USD', 'price' => $price,
            'volume_h24_usd' => 600000, 'volume_h1_usd' => 100000, 'volume_h6_usd' => 400000,
            'price_change_h24_pct' => 3, 'spread_bps' => 5, 'candles_h1_count' => 30,
        ]);
    }

    private function freshPosition(): Position
    {
        return new Position([
            'mode' => 'paper', 'strategy' => 'json', 'product_id' => 'BTC-USD', 'status' => 'open', 'side' => 'long',
            'quantity' => 1000.0, 'entry_price' => 100.0, 'entry_usd' => 100000.0, 'fees_usd' => 0.0,
            'peak_price' => 100.0, 'last_price' => 100.0, 'adds_count' => 0, 'trims_count' => 0,
            'opened_at' => Carbon::parse('2024-01-01 00:00:00'), 'meta' => [],
        ]);
    }

    /** Mimics Desk::trim()/Desk::bookAdd(): a TRIM shrinks quantity/entry_usd by the same fraction (avg unchanged); an ADD moves the average by the weighted-average formula bookAdd() uses. */
    private function apply(Position $p, RiskDecision $d): void
    {
        if ($d->shouldTrim()) {
            $p->entry_usd = (float) $p->entry_usd * (1 - $d->fraction);
            $p->quantity = (float) $p->quantity * (1 - $d->fraction);
            $p->trims_count++;
        } elseif ($d->shouldAdd()) {
            $p->quantity += $d->dollars / $d->limitPrice;
            $p->entry_usd += $d->dollars;
            $p->entry_price = $p->entry_usd / $p->quantity;
            $p->adds_count++;
        }
    }

    private function step(Position $p, DeskContext $ctx, float $price): RiskDecision
    {
        $p->markPrice($price);
        $d = (new JsonPluginStrategy)->risk($p, $this->stats($price), $ctx);
        $this->apply($p, $d);

        return $d;
    }

    #[Test]
    public function the_ladder_dips_into_a_reentry_rearms_and_runs_the_ttp(): void
    {
        $this->plugin('smx-pi-a');
        $ctx = $this->ctx('smx-pi-a');
        $p = $this->freshPosition();

        // (a) walk up through all four rungs (at_pct 1.5/3.0/4.5/6.0): each trim is 2/4/8/10% of the
        // ORIGINAL 1000 units, expressed as a fraction of whatever quantity remains at that moment.
        $d0 = $this->step($p, $ctx, 101.5);
        $this->assertTrue($d0->shouldTrim());
        $this->assertEqualsWithDelta(20 / 1000, $d0->fraction, 1e-9, 'rung 0: 2% of original');

        $d1 = $this->step($p, $ctx, 103.0);
        $this->assertEqualsWithDelta(40 / 980, $d1->fraction, 1e-9, 'rung 1: 4% of original');

        $d2 = $this->step($p, $ctx, 104.5);
        $this->assertEqualsWithDelta(80 / 940, $d2->fraction, 1e-9, 'rung 2: 8% of original');

        $d3 = $this->step($p, $ctx, 106.0);
        $this->assertEqualsWithDelta(100 / 860, $d3->fraction, 1e-9, 'rung 3: 10% of original');
        $this->assertEqualsWithDelta(760.0, $p->quantity, 1e-9);
        $this->assertEqualsWithDelta(100.0, $p->entry_price, 1e-9, 'trims never move the average');

        // (b) dip 0.9 points (retrace.min 0.5 x the 1.5%-wide rung spacing = 0.75, comfortably
        // cleared) from the last fired rung's sale price (106) toward avg (100) and stay above it:
        // reentry arms and buys the formula's exact quantity (sold_qty=100, retrace_pct=0.9 ->
        // 100 x min(1, 0.9*pi/1.5) = 100, the formula clamps to the full sold quantity).
        $dReentry = $this->step($p, $ctx, 105.1);
        $this->assertTrue($dReentry->shouldAdd());
        $this->assertEqualsWithDelta(100.0, $dReentry->dollars / $dReentry->limitPrice, 1e-6, 'buys the formula quantity');
        $this->assertSame(1, $p->adds_count);
        $avgAfterReentry = $p->entry_price;
        $this->assertGreaterThan(100.0, $avgAfterReentry);

        // The pending lot confirms on the very next call (adds_count advanced) — and that same call
        // (d) finds `avg` has moved, rebuilding the ladder from scratch: `fired` resets, `original_qty`
        // re-bases to the CURRENT quantity (860), so this rung-0 fire is 2% of 860, not of 1000.
        $qtyAtRearm = $p->quantity;
        $dRearmed = $this->step($p, $ctx, 105.1);
        $this->assertTrue($dRearmed->shouldTrim());
        $this->assertEqualsWithDelta(0.02, $dRearmed->fraction, 1e-9, 'rung 0 of the RE-ARMED ladder: 2% of the new original (860)');
        $ladder = $p->meta['v2']['ladder'];
        $this->assertSame([], $ladder['fired'], 'rung 0 is only PENDING until the trim confirms next call (reconcilePendingRung)');
        $this->assertSame(0, $ladder['pending']['idx']);
        $this->assertEqualsWithDelta(860.0, $ladder['original_qty'], 1e-9);
        $this->assertEqualsWithDelta($qtyAtRearm, $ladder['original_qty'], 1e-9);
        $this->assertTrue($p->meta['v2']['reentries'][0]['confirmed']);

        // (c) a tiny further tick makes the re-bought lot (in @ 105.1) green after fees (rate 0
        // here, so any positive move qualifies) — cash_out fires AHEAD of the still-unfired rungs,
        // since reentry.cash_out is checked before take_profit.ladder every call. It only sets
        // `cash_out_pending`, not `cashed_out`, until reconcilePendingCashOut sees the trim confirm
        // on the NEXT call (mirroring reconcilePendingRung) — asserted after dRung1New below.
        $qtyBeforeCashOut = $p->quantity;
        $dCashOut = $this->step($p, $ctx, 105.12);
        $this->assertTrue($dCashOut->shouldTrim());
        $this->assertStringContainsString('reentry.cash_out', $dCashOut->ruleFired);
        $this->assertEqualsWithDelta(80 / $qtyBeforeCashOut, $dCashOut->fraction, 1e-9, '80% of the 100-unit reentry lot');

        // Rungs 1-3 of the re-armed ladder, now that cash_out is out of the way. The first of these
        // calls is also what confirms the cash-out above (reconcilePendingCashOut runs before any
        // decision is made).
        $qtyBeforeRung1 = $p->quantity;
        $dRung1New = $this->step($p, $ctx, 107.0);
        $this->assertTrue($p->meta['v2']['reentries'][0]['cashed_out'], 'confirmed once trims_count advanced past the cash-out trim');
        $this->assertStringContainsString('take_profit.ladder.1', $dRung1New->ruleFired);
        $this->assertEqualsWithDelta(0.04 * 860 / $qtyBeforeRung1, $dRung1New->fraction, 1e-9);

        $qtyBeforeRung2 = $p->quantity;
        $dRung2New = $this->step($p, $ctx, 107.0);
        $this->assertStringContainsString('take_profit.ladder.2', $dRung2New->ruleFired);
        $this->assertEqualsWithDelta(0.08 * 860 / $qtyBeforeRung2, $dRung2New->fraction, 1e-9);

        $qtyBeforeRung3 = $p->quantity;
        $dRung3New = $this->step($p, $ctx, 107.0);
        $this->assertStringContainsString('take_profit.ladder.3', $dRung3New->ruleFired);
        $this->assertEqualsWithDelta(0.10 * 860 / $qtyBeforeRung3, $dRung3New->fraction, 1e-9);

        // (e) ride to a new peak, then give back more than 1% from it: the runner closes the remainder.
        $p->markPrice(112.0);
        $this->step($p, $ctx, 112.0);   // marks the peak, holds (base rails: nothing else fires)
        $dTtp = $this->step($p, $ctx, 109.0);
        $this->assertTrue($dTtp->shouldClose());
        $this->assertSame('take_profit.runner.ttp', $dTtp->ruleFired);
    }

    #[Test]
    public function the_stop_moves_with_the_average_after_a_reentry_buy(): void
    {
        $this->plugin('smx-pi-f');
        $ctx = $this->ctx('smx-pi-f');
        $p = $this->freshPosition();

        $this->step($p, $ctx, 101.5);   // rung 0 fires, avg stays 100
        $dAdd = $this->step($p, $ctx, 100.7);   // a comfortable retrace past the 0.75-point minimum: reentry buys
        $this->assertTrue($dAdd->shouldAdd());
        $this->step($p, $ctx, 100.7);   // confirms the add; avg is now > 100

        $avg = $p->entry_price;
        $this->assertGreaterThan(100.0, $avg, 'the reentry buy moved the average up');
        $stopPrice = $avg * (1 - 0.025);

        $dHold = $this->step($p, $ctx, $stopPrice + 0.01);
        $this->assertFalse($dHold->shouldClose(), 'just above the (moved) stop still holds');

        $dStop = $this->step($p, $ctx, $stopPrice - 0.01);
        $this->assertTrue($dStop->shouldClose());
        $this->assertSame('stop.pct_from_avg', $dStop->ruleFired);
    }

    #[Test]
    public function reset_on_add_false_keeps_fired_rungs_and_only_reprices_the_ladder(): void
    {
        $this->plugin('smx-pi-noreset', ['take_profit' => ['reset_on_add' => false]]);
        $ctx = $this->ctx('smx-pi-noreset');
        $p = $this->freshPosition();

        $d0 = $this->step($p, $ctx, 101.5);
        $this->assertTrue($d0->shouldTrim());
        $this->assertSame(0, $p->meta['v2']['ladder']['pending']['idx'], 'rung 0 is pending — apply()\'s trims_count++ confirms it next call');

        // A manual add (bypassing reentry — this definition still has one, but we're isolating
        // reset_on_add here) moves the average without touching the ladder's own bookkeeping.
        $p->entry_usd += 10100.0;
        $p->quantity += 100.0;
        $p->entry_price = $p->entry_usd / $p->quantity;
        $p->adds_count++;

        $this->step($p, $ctx, 100.6);   // below every rung target; just re-prices the ladder
        $ladder = $p->meta['v2']['ladder'];
        $this->assertSame([0], $ladder['fired'], 'reset_on_add:false keeps rungs already fired');
        $this->assertEqualsWithDelta(1000.0, $ladder['original_qty'], 1e-9, 'original_qty is NOT re-based');
        $this->assertGreaterThan(100.0, $ladder['avg'], 'but the stored avg used to price remaining rungs does move');
    }

    #[Test]
    public function avg_recovers_the_true_fill_price_on_the_fee_exclusive_whole_contract_perps_convention(): void
    {
        $this->plugin('smx-pi-fee-excl');
        $ctx = $this->ctx('smx-pi-fee-excl');
        $p = $this->freshPosition();
        $p->quantity = 1.0;
        $p->entry_price = 100.0;
        $p->entry_usd = 100.0;
        $strategy = new JsonPluginStrategy;

        $strategy->risk($p, $this->stats(100.0), $ctx);
        $this->assertEqualsWithDelta(100.0, $p->meta['v2']['avg'], 1e-9);

        // A whole-contract/margin add of 1.0 unit at 110: on that convention Desk::bookAdd() books
        // filledUsd = Lot::notional (fee-EXCLUSIVE) into entry_usd and the fee only into fees_usd +
        // meta.entry_fee_excluded (Desk.php review round 3) — unlike the plain spot path, where
        // entry_usd already carries the fee.
        $notional = 1.0 * 110.0;
        $fee = $notional * 0.006;
        $p->quantity += 1.0;
        $p->entry_usd += $notional;
        $p->fees_usd += $fee;
        $p->entry_price = $p->entry_usd / $p->quantity;
        $p->adds_count++;
        $meta = $p->meta;
        $meta['entry_fee_excluded'] = (float) ($meta['entry_fee_excluded'] ?? 0) + $fee;
        $p->meta = $meta;

        $strategy->risk($p, $this->stats(110.0), $ctx);

        // 1.0 @ 100 + 1.0 @ 110, both legs fee-exclusive -> exactly the quantity-weighted price
        // (105.00), not the ~104.67 the old one-term fee subtraction produced by charging a fee
        // that was never folded into entry_usd on this convention.
        $this->assertEqualsWithDelta(105.0, $p->meta['v2']['avg'], 1e-9);
    }

    #[Test]
    public function position_avg_reads_the_engine_avg_not_entry_price_on_the_fee_inclusive_spot_convention(): void
    {
        $this->plugin('smx-pi-spot-fee');
        $ctx = $this->ctx('smx-pi-spot-fee');
        $p = $this->freshPosition();
        $p->quantity = 1.0;
        $p->entry_price = 100.0;
        $p->entry_usd = 100.0;
        $strategy = new JsonPluginStrategy;

        $strategy->risk($p, $this->stats(100.0), $ctx);
        $this->assertEqualsWithDelta(100.0, $p->meta['v2']['avg'], 1e-9);

        // A plain cash-notional (spot) add of $110: entry_usd books the FULL requested dollars
        // (fee already inside — Desk::bookAdd()/Backtester's own bookAdd on this path), the fee
        // only shaves the filled quantity; meta.entry_fee_excluded stays untouched (0), matching
        // the convention Desk sets it for on this path (Desk.php review round 3).
        $usd = 110.0;
        $fee = $usd * 0.006;
        $filledQty = ($usd - $fee) / 110.0;
        $p->quantity += $filledQty;
        $p->entry_usd += $usd;
        $p->fees_usd += $fee;
        $p->entry_price = $p->entry_usd / $p->quantity;
        $p->adds_count++;

        $strategy->risk($p, $this->stats(110.0), $ctx);
        $avg = $p->meta['v2']['avg'];

        // entry_price recomputes as entry_usd / quantity — a fee-INCLUSIVE cost over a
        // fee-adjusted quantity — and jumps away from the true fill price with no price move at
        // all; JsonRuleEvaluator's `position.avg` must read the engine's own avg(), the same
        // number stop.pct_from_avg / the ladder targets / the runner all read, not this.
        $this->assertNotEqualsWithDelta((float) $p->entry_price, $avg, 1e-4, 'entry_price and the engine avg must differ on this convention or the test proves nothing');
        $this->assertEqualsWithDelta($avg, JsonRuleEvaluator::value('position.avg', $this->stats(110.0), $p, $ctx), 1e-9);
    }

    #[Test]
    public function green_after_fees_requires_the_move_to_clear_both_legs_fee(): void
    {
        $long = new Position(['side' => 'long']);
        $short = new Position(['side' => 'short']);

        // Long, bought at 100, qty 10, 0.1% taker each way: fees = (100+101)*10*0.001 = 2.01.
        // pnl at 101 = (101-100)*10 = 10 > 2.01 -> green. At 100.1, pnl = 1 <= fees -> not green.
        $this->assertTrue(JsonPluginStrategy::greenAfterFees($long, 101.0, 100.0, 10.0, 0.001));
        $this->assertFalse(JsonPluginStrategy::greenAfterFees($long, 100.1, 100.0, 10.0, 0.001));

        // Short: green when price FALLS below the entry enough to clear both legs' fees.
        $this->assertTrue(JsonPluginStrategy::greenAfterFees($short, 99.0, 100.0, 10.0, 0.001));
        $this->assertFalse(JsonPluginStrategy::greenAfterFees($short, 99.9, 100.0, 10.0, 0.001));

        // Fail-closed on garbage inputs rather than dividing by zero or flipping sign.
        $this->assertFalse(JsonPluginStrategy::greenAfterFees($long, 101.0, 0.0, 10.0, 0.001));
        $this->assertFalse(JsonPluginStrategy::greenAfterFees($long, 101.0, 100.0, 0.0, 0.001));
    }

    #[Test]
    public function runner_ttp_does_not_arm_just_because_there_is_no_ladder_to_exhaust(): void
    {
        $def = json_decode(file_get_contents(base_path('resources/strategies/examples/smx-pi-take-profit-v2.json')), true);
        $def['key'] = 'smx-pi-no-ladder';
        $def['take_profit']['ladder'] = [];
        $def['take_profit']['runner']['ttp'] = ['activate_pct' => 2.0, 'giveback_pct' => 1.0];
        unset($def['reentry']);
        StrategyPlugin::create(['key' => $def['key'], 'name' => $def['meta']['name'], 'definition' => $def]);
        $ctx = $this->ctx('smx-pi-no-ladder');
        $p = $this->freshPosition();

        $this->step($p, $ctx, 100.1);   // a tiny peak, nowhere near the 2% activate_pct
        // Before the fix, an empty ladder counted as "exhausted" and armed the runner outright, so
        // a hard crash from that tiny peak closed the position long before activate_pct was ever
        // reached. It must still just hold.
        $d = $this->step($p, $ctx, 98.0);
        $this->assertFalse($d->shouldClose(), 'an empty ladder is not "exhausted" into an armed runner');
    }

    #[Test]
    public function cash_out_remainder_ladder_only_folds_the_leftover_when_reset_on_add_is_false(): void
    {
        $this->plugin('smx-pi-remainder-reset', ['reentry' => ['cash_out' => ['remainder' => 'ladder']]]);
        $pReset = $this->freshPosition();
        $this->runRungReentryCashOut($pReset, $this->ctx('smx-pi-remainder-reset'));
        // reset_on_add:true (the default) already rebuilt the ladder from the CURRENT quantity —
        // which already includes the whole re-bought lot — when the reentry buy moved avg. Folding
        // the un-cashed remainder in again here would double-count it.
        $this->assertEqualsWithDelta(1000.0, $pReset->meta['v2']['ladder']['original_qty'], 1e-9);

        $this->plugin('smx-pi-remainder-noreset', [
            'take_profit' => ['reset_on_add' => false],
            'reentry' => ['cash_out' => ['remainder' => 'ladder']],
        ]);
        $pNoReset = $this->freshPosition();
        $this->runRungReentryCashOut($pNoReset, $this->ctx('smx-pi-remainder-noreset'));
        // reset_on_add:false never rebases original_qty on an add, so the 4 units of the re-bought
        // lot (20 bought, 16 cashed out) must be folded in by hand.
        $this->assertEqualsWithDelta(1004.0, $pNoReset->meta['v2']['ladder']['original_qty'], 1e-9);
    }

    /** Rung 0 fires (sells 20 of 1000), a reentry buys back 20 units at a retrace, then a tick cashes out 80% (16 of 20) of that lot green after (zero) fees — a final same-price call lets reconcilePendingCashOut confirm the trim before the caller reads `original_qty`. */
    private function runRungReentryCashOut(Position $p, DeskContext $ctx): void
    {
        $this->step($p, $ctx, 101.5);
        $dAdd = $this->step($p, $ctx, 100.7);
        $this->assertTrue($dAdd->shouldAdd());
        $dCashOut = $this->step($p, $ctx, 100.8);
        $this->assertTrue($dCashOut->shouldTrim());
        $this->assertStringContainsString('reentry.cash_out', $dCashOut->ruleFired);
        $this->step($p, $ctx, 100.8);
    }

    #[Test]
    public function min_spacing_x_fees_does_not_block_the_shipped_example_at_the_default_taker_rate(): void
    {
        $this->plugin('smx-pi-real-fees');
        $ctx = $this->ctx('smx-pi-real-fees', 0.006);   // desk.fees.taker_rate default
        $p = $this->freshPosition();

        $this->step($p, $ctx, 101.5);   // rung 0 fires
        // min_spacing_x_fees 1 x the 1.2% round-trip fee = 1.2%, which rung 0's 1.5-point spacing
        // (measured from 0, the first fired rung) clears.
        $d = $this->step($p, $ctx, 100.7);
        $this->assertTrue($d->shouldAdd(), 'the acceptance strategy\'s re-entry must still arm at the desk\'s real default fee rate');
    }

    #[Test]
    public function max_per_position_refuses_a_second_reentry_even_when_every_other_gate_passes(): void
    {
        $this->plugin('smx-pi-maxpos', ['reentry' => ['cash_out' => null, 'max_per_position' => 1]]);
        $ctx = $this->ctx('smx-pi-maxpos');
        $p = $this->freshPosition();

        $this->step($p, $ctx, 101.5);            // rung 0 fires
        $dAdd = $this->step($p, $ctx, 100.7);    // reentry #1 arms
        $this->assertTrue($dAdd->shouldAdd());
        $this->step($p, $ctx, 100.7);            // confirms it; avg moves, the ladder re-arms from it

        $this->step($p, $ctx, 101.52);           // rearmed rung 0 fires again
        $dSecond = $this->step($p, $ctx, 100.7); // every reentry gate would otherwise pass again
        $this->assertFalse($dSecond->shouldAdd(), 'max_per_position:1 must refuse a second reentry');
        $this->assertCount(1, $p->meta['v2']['reentries']);
    }

    #[Test]
    public function stop_anchor_entry_stays_pinned_to_the_first_fill_after_a_reentry_buy(): void
    {
        $this->plugin('smx-pi-anchor-entry', ['stop' => ['anchor' => 'entry']]);
        $ctx = $this->ctx('smx-pi-anchor-entry');
        $p = $this->freshPosition();

        $this->step($p, $ctx, 101.5);          // rung 0 fires, avg stays 100
        $dAdd = $this->step($p, $ctx, 100.7);  // reentry buys, moving avg
        $this->assertTrue($dAdd->shouldAdd());
        $this->step($p, $ctx, 100.7);          // confirms the add

        $this->assertGreaterThan(100.0, $p->entry_price, 'avg did move');
        $this->assertEqualsWithDelta(100.0, $p->meta['v2']['entry_price'], 1e-9, 'anchor:"entry" stays pinned to the first fill, unlike avg');

        $stopPrice = 100.0 * (1 - 0.025);
        $dHold = $this->step($p, $ctx, $stopPrice + 0.01);
        $this->assertFalse($dHold->shouldClose(), 'still above the fixed entry anchor');

        $dStop = $this->step($p, $ctx, $stopPrice - 0.01);
        $this->assertTrue($dStop->shouldClose());
        $this->assertSame('stop.pct_from_avg', $dStop->ruleFired);
    }

    #[Test]
    public function retrace_of_pct_measures_an_absolute_move_not_a_multiple_of_rung_spacing(): void
    {
        $this->plugin('smx-pi-retrace-pct', ['reentry' => ['retrace' => ['of' => 'pct', 'min' => 0.4, 'stay_above_avg' => true]]]);
        $ctx = $this->ctx('smx-pi-retrace-pct');
        $p = $this->freshPosition();

        $this->step($p, $ctx, 101.5);   // rung 0 fires at avg 100, sale price 101.5

        // A 0.3% retrace falls under the flat 0.4% bar regardless of how it's read.
        $dShort = $this->step($p, $ctx, 101.2);
        $this->assertFalse($dShort->shouldAdd(), 'retrace.of:"pct" needs the full 0.4%');

        // A 0.5% retrace clears the flat 0.4% bar, but would FAIL the (wrong) rung_spacing reading
        // (0.4 x the 1.5%-wide rung spacing = 0.6%) — proving retrace.of:"pct" is read as an
        // absolute percent, not a multiple of the spacing.
        $dEnough = $this->step($p, $ctx, 101.0);
        $this->assertTrue($dEnough->shouldAdd());
    }

    #[Test]
    public function reentry_lot_confirms_from_the_actual_post_fill_state_not_the_pre_fill_intent(): void
    {
        $this->plugin('smx-pi-real-fill');
        $ctx = $this->ctx('smx-pi-real-fill');
        $p = $this->freshPosition();
        $strategy = new JsonPluginStrategy;

        $this->step($p, $ctx, 101.5);   // rung 0 fires

        $p->markPrice(100.7);
        $dAdd = $strategy->risk($p, $this->stats(100.7), $ctx);
        $this->assertTrue($dAdd->shouldAdd());
        $intendedPrice = $dAdd->limitPrice;
        $intendedQty = $dAdd->dollars / $intendedPrice;

        // A real fill worse than the naive intent — slippage on price, a taker fee shaving the
        // filled quantity — exactly what Backtester::bookAdd() does and the old code ignored
        // entirely (it recorded $dAdd's own pre-fill price/qty verbatim).
        $qtyBefore = $p->quantity;
        $usdBefore = $p->entry_usd;
        $filledPx = $intendedPrice * 1.001;
        $fee = $dAdd->dollars * 0.006;
        $filledQty = ($dAdd->dollars - $fee) / $filledPx;
        $p->quantity = $qtyBefore + $filledQty;
        $p->entry_usd = $usdBefore + $dAdd->dollars;
        $p->entry_price = $p->entry_usd / $p->quantity;
        $p->adds_count++;
        $this->assertNotEqualsWithDelta($intendedQty, $filledQty, 1e-9, 'the simulated fill must differ from the naive intent or this test proves nothing');

        $p->markPrice(100.7);
        $strategy->risk($p, $this->stats(100.7), $ctx);   // confirms the lot
        $lot = $p->meta['v2']['reentries'][0];
        $this->assertTrue($lot['confirmed']);
        $this->assertEqualsWithDelta($filledQty, $lot['qty'], 1e-9, 'the lot must record the REAL filled quantity');
        $this->assertEqualsWithDelta($dAdd->dollars / $filledQty, $lot['price'], 1e-9, 'and the real per-unit cost, not the pre-fill intent');
        $this->assertNotEqualsWithDelta($intendedPrice, $lot['price'], 1e-6, 'which must differ from the naive intended price');
    }

    #[Test]
    public function a_zero_quantity_fill_drops_the_pending_reentry_lot_instead_of_confirming_it(): void
    {
        $this->plugin('smx-pi-zero-fill');
        $ctx = $this->ctx('smx-pi-zero-fill');
        $p = $this->freshPosition();
        $strategy = new JsonPluginStrategy;

        $this->step($p, $ctx, 101.5);   // rung 0 fires
        $p->markPrice(100.7);
        $dAdd = $strategy->risk($p, $this->stats(100.7), $ctx);
        $this->assertTrue($dAdd->shouldAdd());
        $this->assertCount(1, $p->meta['v2']['reentries'], 'the pending lot was recorded');

        // adds_count advances for some unrelated reason (a trim in the same window offsetting it,
        // a counter bump elsewhere) but quantity never moved — the add never actually filled.
        $p->adds_count++;

        // Below avg: retrace.stay_above_avg (default true) blocks a fresh re-arm on this same
        // call, so the only thing this RISK call can do with the stale lot is reconcile it —
        // isolating the drop from reentryArmDecision() immediately replacing it with a new one.
        $p->markPrice(99.9);
        $strategy->risk($p, $this->stats(99.9), $ctx);

        $this->assertSame([], $p->meta['v2']['reentries'], 'a zero-fill lot is dropped, never promoted to confirmed carrying the stale pre-fill intent');
    }

    #[Test]
    public function ladder_trim_carries_the_stop_price_for_the_conservative_touch_policy(): void
    {
        $this->plugin('smx-pi-stopmeta');
        $ctx = $this->ctx('smx-pi-stopmeta');
        $p = $this->freshPosition();

        $d = $this->step($p, $ctx, 101.5);   // rung 0 fires
        $this->assertTrue($d->shouldTrim());
        // shipped example: stop.pct_from_avg 2.5, anchor "avg" (100) -> stop price 97.5. Backtester's
        // own conservative exit_touch_policy reads this to race the rung against the stop intrabar.
        $this->assertEqualsWithDelta(97.5, $d->meta['stop_price'] ?? null, 1e-9);
    }

    #[Test]
    public function take_profit_rules_close_the_position_v1_migration_compatibility(): void
    {
        // docs/STRATEGY_SCHEMA_V2.md "Migration v1 -> v2": exit.take_profit.rules -> take_profit.rules,
        // kept as close-only rules. A hand-authored v2 definition can set the same section directly.
        $this->plugin('smx-pi-tprules', ['take_profit' => ['rules' => [
            ['field' => 'volume_ratio_6h', 'op' => '<', 'value' => 0.1],
        ]]]);
        $ctx = $this->ctx('smx-pi-tprules');
        $p = $this->freshPosition();

        $stats = ProductStats::fromArray([
            'product_id' => 'BTC-USD', 'price' => 100.1,
            'volume_h24_usd' => 600000, 'volume_h1_usd' => 100000, 'volume_h6_usd' => 10000,
            'price_change_h24_pct' => 3, 'spread_bps' => 5, 'candles_h1_count' => 30,
        ]);
        $p->markPrice(100.1);
        $d = (new JsonPluginStrategy)->risk($p, $stats, $ctx);
        $this->assertTrue($d->shouldClose());
        $this->assertStringContainsString('take_profit.rules', $d->ruleFired);
    }

    #[Test]
    public function reentry_below_min_ticket_usd_produces_no_order(): void
    {
        $this->plugin('smx-pi-tinyreentry', ['reentry' => ['size' => ['mode' => 'formula', 'expr' => 'sold_qty * 0.0000001']]]);
        $ctx = $this->ctx('smx-pi-tinyreentry');
        $p = $this->freshPosition();

        $this->step($p, $ctx, 101.5);   // rung 0 fires
        // Every other reentry gate passes here (same retrace as the other tests) — the ticket
        // itself is a fraction of a cent and must be refused, not booked.
        $d = $this->step($p, $ctx, 100.7);
        $this->assertFalse($d->shouldAdd(), 'size.min_ticket_usd must still clamp a reentry order to nothing');
    }

    #[Test]
    public function an_adds_rung_still_fires_after_a_confirmed_reentry_consumed_the_shared_counter(): void
    {
        // Both `adds` and `reentry` book through Desk::bookAdd() and so both advance the same
        // `position.adds_count` — addsDecisionV2() must index its own rungs by `meta.v2.adds_fired`,
        // not that shared counter, or a confirmed reentry silently consumes (skips) an adds rung.
        $this->plugin('smx-pi-adds-reentry', [
            'adds' => [['trigger' => ['field' => 'price', 'op' => '<', 'value' => 99], 'size_pct' => 5]],
        ]);
        $ctx = $this->ctx('smx-pi-adds-reentry');
        $p = $this->freshPosition();

        $this->step($p, $ctx, 101.5);   // rung 0 fires
        $dReentry = $this->step($p, $ctx, 100.7);   // reentry arms and buys
        $this->assertTrue($dReentry->shouldAdd());
        $this->assertSame(1, $p->adds_count, 'the reentry buy advanced the SHARED adds_count counter');
        $this->step($p, $ctx, 100.7);   // confirms the reentry lot; adds_fired is still 0 — nothing has fired yet

        // Before the fix, addsDecisionV2() indexed by position.adds_count (already 1 here) and
        // looked up adds[1], which does not exist — silently skipping adds[0] forever.
        $dAdds = $this->step($p, $ctx, 98.9);
        $this->assertTrue($dAdds->shouldAdd(), 'adds[0] must still fire — its trigger is met and it has never fired');
        $this->assertSame('adds.0', $dAdds->ruleFired);
        $this->assertSame(2, $p->adds_count, 'adds also books through the shared counter');

        $this->step($p, $ctx, 98.9);   // confirms the pending adds rung
        $this->assertSame(1, $p->meta['v2']['adds_fired']);
    }

    #[Test]
    public function an_adds_rung_that_lowers_avg_does_not_stale_arm_the_runner(): void
    {
        // Review round 2: runnerTtpDecision() measured peakPct off position->peak_price, an
        // ALL-TIME high that survives an `adds` rearm. Pyramiding into a dip lowers avg with no
        // price move, which raises peakPct against the stale peak and can close the whole
        // remaining position on the very next RISK call. Not reachable with the shipped SMX pi
        // example (reentry.stay_above_avg means a re-buy only ever raises avg) but reachable for
        // any v2 definition combining `adds` with `take_profit.runner.ttp` — exactly what
        // v1ToV2() produces from `management.adds` + `management.trailing`.
        $this->plugin('smx-pi-adds-peak', [
            'reentry' => null,
            'stop' => ['pct_from_avg' => 20],
            'take_profit' => [
                'ladder' => [['at_pct' => 50.0, 'sell_pct_of_original' => 100]],
                'runner' => ['ttp' => ['activate_pct' => 5.0, 'giveback_pct' => 1.0]],
            ],
            'adds' => [['trigger' => ['field' => 'price', 'op' => '<', 'value' => 97], 'size_pct' => 80]],
        ]);
        $ctx = $this->ctx('smx-pi-adds-peak');
        $p = $this->freshPosition();

        // Peak reaches +5.9% from avg (100) -- past activate_pct (5.0) but still inside the 1-point
        // giveback, so the runner arms without closing.
        $dPeak = $this->step($p, $ctx, 105.9);
        $this->assertFalse($dPeak->shouldClose());

        // A hard dip below 97 triggers the adds rung (80% of entry_usd, bought at 96) -- this call
        // returns the ADD itself, never reaching runnerTtpDecision.
        $dAdd = $this->step($p, $ctx, 96.0);
        $this->assertTrue($dAdd->shouldAdd());
        $avgAfterAdd = $p->entry_price;
        $this->assertLessThan(100.0, $avgAfterAdd, 'pyramiding into the dip pulled the average down');

        // The add confirms and avg's move rearms the ladder on this call. Pre-fix, peakPct is
        // computed against the stale 105.9 peak and the old (now lower) avg, which reads as a
        // giveback well past 1 point and closes the position outright -- with no new high ever
        // printed after the add. Post-fix, the ladder's own peak was rebased to the add's own
        // dip price, so peakPct is negative and the runner stays unarmed.
        $dAfterAdd = $this->step($p, $ctx, 96.0);
        $this->assertFalse($dAfterAdd->shouldClose(), 'the runner must not fire off a peak that predates the rearm');
        $this->assertNotSame('take_profit.runner.ttp', $dAfterAdd->ruleFired);

        // position.peak_pct (JsonRuleEvaluator::value()) must read the same rebased peak the runner
        // just did, not $p->peak_price's stale all-time high -- a stop.rules/take_profit.rules rule
        // written against position.peak_pct is otherwise stale-armed by the exact bug this test
        // covers for the runner.
        $peakPct = JsonRuleEvaluator::value('position.peak_pct', $this->stats(96.0), $p, $ctx);
        $this->assertLessThan(0.0, $peakPct, 'position.peak_pct must read the rebased ladder peak, not the stale all-time high');
    }
}
