<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Data\ProductStats;
use App\Desk\Data\RiskDecision;
use App\Desk\DeskContext;
use App\Desk\Strategies\JsonPluginStrategy;
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

        // (a) walk up through all four rungs: each trim is 2/4/8/10% of the ORIGINAL 1000 units,
        // expressed as a fraction of whatever quantity remains at that moment.
        $d0 = $this->step($p, $ctx, 100.5);
        $this->assertTrue($d0->shouldTrim());
        $this->assertEqualsWithDelta(20 / 1000, $d0->fraction, 1e-9, 'rung 0: 2% of original');

        $d1 = $this->step($p, $ctx, 101.0);
        $this->assertEqualsWithDelta(40 / 980, $d1->fraction, 1e-9, 'rung 1: 4% of original');

        $d2 = $this->step($p, $ctx, 101.5);
        $this->assertEqualsWithDelta(80 / 940, $d2->fraction, 1e-9, 'rung 2: 8% of original');

        $d3 = $this->step($p, $ctx, 102.0);
        $this->assertEqualsWithDelta(100 / 860, $d3->fraction, 1e-9, 'rung 3: 10% of original');
        $this->assertEqualsWithDelta(760.0, $p->quantity, 1e-9);
        $this->assertEqualsWithDelta(100.0, $p->entry_price, 1e-9, 'trims never move the average');

        // (b) dip half a rung (retrace.min 0.5 x the 0.5%-wide rung spacing) from the last fired
        // rung's sale price (102) toward avg (100) and stay above it: reentry arms and buys the
        // formula's exact quantity (sold_qty=100, retrace_pct=0.3 -> 100 x min(1, 0.3*pi/0.5) = 100).
        $dReentry = $this->step($p, $ctx, 101.7);
        $this->assertTrue($dReentry->shouldAdd());
        $this->assertEqualsWithDelta(100.0, $dReentry->dollars / $dReentry->limitPrice, 1e-6, 'buys the formula quantity');
        $this->assertSame(1, $p->adds_count);
        $avgAfterReentry = $p->entry_price;
        $this->assertGreaterThan(100.0, $avgAfterReentry);

        // The pending lot confirms on the very next call (adds_count advanced) — and that same call
        // (d) finds `avg` has moved, rebuilding the ladder from scratch: `fired` resets, `original_qty`
        // re-bases to the CURRENT quantity (860), so this rung-0 fire is 2% of 860, not of 1000.
        $qtyAtRearm = $p->quantity;
        $dRearmed = $this->step($p, $ctx, 101.7);
        $this->assertTrue($dRearmed->shouldTrim());
        $this->assertEqualsWithDelta(0.02, $dRearmed->fraction, 1e-9, 'rung 0 of the RE-ARMED ladder: 2% of the new original (860)');
        $ladder = $p->meta['v2']['ladder'];
        $this->assertSame([], $ladder['fired'], 'rung 0 is only PENDING until the trim confirms next call (reconcilePendingRung)');
        $this->assertSame(0, $ladder['pending']['idx']);
        $this->assertEqualsWithDelta(860.0, $ladder['original_qty'], 1e-9);
        $this->assertEqualsWithDelta($qtyAtRearm, $ladder['original_qty'], 1e-9);
        $this->assertTrue($p->meta['v2']['reentries'][0]['confirmed']);

        // (c) a tiny further tick makes the re-bought lot (in @ 101.7) green after fees (rate 0
        // here, so any positive move qualifies) — cash_out fires AHEAD of the still-unfired rungs,
        // since reentry.cash_out is checked before take_profit.ladder every call.
        $qtyBeforeCashOut = $p->quantity;
        $dCashOut = $this->step($p, $ctx, 101.72);
        $this->assertTrue($dCashOut->shouldTrim());
        $this->assertStringContainsString('reentry.cash_out', $dCashOut->ruleFired);
        $this->assertTrue($p->meta['v2']['reentries'][0]['cashed_out']);
        $this->assertEqualsWithDelta(80 / $qtyBeforeCashOut, $dCashOut->fraction, 1e-9, '80% of the 100-unit reentry lot');

        // Rungs 1-3 of the re-armed ladder, now that cash_out is out of the way.
        $qtyBeforeRung1 = $p->quantity;
        $dRung1New = $this->step($p, $ctx, 101.72);
        $this->assertStringContainsString('take_profit.ladder.1', $dRung1New->ruleFired);
        $this->assertEqualsWithDelta(0.04 * 860 / $qtyBeforeRung1, $dRung1New->fraction, 1e-9);

        $qtyBeforeRung2 = $p->quantity;
        $dRung2New = $this->step($p, $ctx, 102.5);
        $this->assertStringContainsString('take_profit.ladder.2', $dRung2New->ruleFired);
        $this->assertEqualsWithDelta(0.08 * 860 / $qtyBeforeRung2, $dRung2New->fraction, 1e-9);

        $qtyBeforeRung3 = $p->quantity;
        $dRung3New = $this->step($p, $ctx, 102.5);
        $this->assertStringContainsString('take_profit.ladder.3', $dRung3New->ruleFired);
        $this->assertEqualsWithDelta(0.10 * 860 / $qtyBeforeRung3, $dRung3New->fraction, 1e-9);

        // (e) ride to a new peak, then give back 1% from it: the runner closes the remainder.
        $p->markPrice(104.0);
        $this->step($p, $ctx, 104.0);   // marks the peak, holds (base rails: nothing else fires)
        $dTtp = $this->step($p, $ctx, 102.9);
        $this->assertTrue($dTtp->shouldClose());
        $this->assertSame('take_profit.runner.ttp', $dTtp->ruleFired);
    }

    #[Test]
    public function the_stop_moves_with_the_average_after_a_reentry_buy(): void
    {
        $this->plugin('smx-pi-f');
        $ctx = $this->ctx('smx-pi-f');
        $p = $this->freshPosition();

        $this->step($p, $ctx, 100.5);   // rung 0 fires, avg stays 100
        $dAdd = $this->step($p, $ctx, 100.2);   // a comfortable half-rung-plus retrace: reentry buys
        $this->assertTrue($dAdd->shouldAdd());
        $this->step($p, $ctx, 100.2);   // confirms the add; avg is now > 100

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

        $d0 = $this->step($p, $ctx, 100.5);
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

    /** Rung 0 fires (sells 20 of 1000), a reentry buys back 20 units at a half-rung retrace, then a flat tick cashes out 80% (16 of 20) of that lot green after (zero) fees. */
    private function runRungReentryCashOut(Position $p, DeskContext $ctx): void
    {
        $this->step($p, $ctx, 100.5);
        $dAdd = $this->step($p, $ctx, 100.2);
        $this->assertTrue($dAdd->shouldAdd());
        $dCashOut = $this->step($p, $ctx, 100.25);
        $this->assertTrue($dCashOut->shouldTrim());
        $this->assertStringContainsString('reentry.cash_out', $dCashOut->ruleFired);
    }

    #[Test]
    public function min_spacing_x_fees_does_not_block_the_shipped_example_at_the_default_taker_rate(): void
    {
        $this->plugin('smx-pi-real-fees');
        $ctx = $this->ctx('smx-pi-real-fees', 0.006);   // desk.fees.taker_rate default
        $p = $this->freshPosition();

        $this->step($p, $ctx, 100.5);   // rung 0 fires
        // min_spacing_x_fees 0.4 x the 1.2% round-trip fee = 0.48%, which the 0.5%-wide rungs clear.
        $d = $this->step($p, $ctx, 100.2);
        $this->assertTrue($d->shouldAdd(), 'the acceptance strategy\'s re-entry must still arm at the desk\'s real default fee rate');
    }

    #[Test]
    public function max_per_position_refuses_a_second_reentry_even_when_every_other_gate_passes(): void
    {
        $this->plugin('smx-pi-maxpos', ['reentry' => ['cash_out' => null, 'max_per_position' => 1]]);
        $ctx = $this->ctx('smx-pi-maxpos');
        $p = $this->freshPosition();

        $this->step($p, $ctx, 100.5);            // rung 0 fires
        $dAdd = $this->step($p, $ctx, 100.2);    // reentry #1 arms
        $this->assertTrue($dAdd->shouldAdd());
        $this->step($p, $ctx, 100.2);            // confirms it; avg moves, the ladder re-arms from it

        $this->step($p, $ctx, 100.51);           // rearmed rung 0 fires again
        $dSecond = $this->step($p, $ctx, 100.2); // every reentry gate would otherwise pass again
        $this->assertFalse($dSecond->shouldAdd(), 'max_per_position:1 must refuse a second reentry');
        $this->assertCount(1, $p->meta['v2']['reentries']);
    }

    #[Test]
    public function stop_anchor_entry_stays_pinned_to_the_first_fill_after_a_reentry_buy(): void
    {
        $this->plugin('smx-pi-anchor-entry', ['stop' => ['anchor' => 'entry']]);
        $ctx = $this->ctx('smx-pi-anchor-entry');
        $p = $this->freshPosition();

        $this->step($p, $ctx, 100.5);          // rung 0 fires, avg stays 100
        $dAdd = $this->step($p, $ctx, 100.2);  // reentry buys, moving avg
        $this->assertTrue($dAdd->shouldAdd());
        $this->step($p, $ctx, 100.2);          // confirms the add

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

        $this->step($p, $ctx, 100.5);   // rung 0 fires at avg 100, sale price 100.5

        // A 0.3% retrace: under the (wrong) rung_spacing reading this would be 0.4 x 0.5% = 0.2%,
        // comfortably cleared — retrace.of:"pct" must require the full 0.4% directly instead.
        $dShort = $this->step($p, $ctx, 100.2);
        $this->assertFalse($dShort->shouldAdd(), 'retrace.of:"pct" needs the full 0.4%, not 0.4 x the rung spacing');

        // A 0.41% retrace clears that same 0.4% absolute bar.
        $dEnough = $this->step($p, $ctx, 100.09);
        $this->assertTrue($dEnough->shouldAdd());
    }

    #[Test]
    public function reentry_lot_confirms_from_the_actual_post_fill_state_not_the_pre_fill_intent(): void
    {
        $this->plugin('smx-pi-real-fill');
        $ctx = $this->ctx('smx-pi-real-fill');
        $p = $this->freshPosition();
        $strategy = new JsonPluginStrategy;

        $this->step($p, $ctx, 100.5);   // rung 0 fires

        $p->markPrice(100.2);
        $dAdd = $strategy->risk($p, $this->stats(100.2), $ctx);
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

        $p->markPrice(100.2);
        $strategy->risk($p, $this->stats(100.2), $ctx);   // confirms the lot
        $lot = $p->meta['v2']['reentries'][0];
        $this->assertTrue($lot['confirmed']);
        $this->assertEqualsWithDelta($filledQty, $lot['qty'], 1e-9, 'the lot must record the REAL filled quantity');
        $this->assertEqualsWithDelta($dAdd->dollars / $filledQty, $lot['price'], 1e-9, 'and the real per-unit cost, not the pre-fill intent');
        $this->assertNotEqualsWithDelta($intendedPrice, $lot['price'], 1e-6, 'which must differ from the naive intended price');
    }

    #[Test]
    public function ladder_trim_carries_the_stop_price_for_the_conservative_touch_policy(): void
    {
        $this->plugin('smx-pi-stopmeta');
        $ctx = $this->ctx('smx-pi-stopmeta');
        $p = $this->freshPosition();

        $d = $this->step($p, $ctx, 100.5);   // rung 0 fires
        $this->assertTrue($d->shouldTrim());
        // shipped example: stop.pct_from_avg 2.5, anchor "avg" (100) -> stop price 97.5. Backtester's
        // own conservative exit_touch_policy reads this to race the rung against the stop intrabar.
        $this->assertEqualsWithDelta(97.5, $d->meta['stop_price'] ?? null, 1e-9);
    }

    #[Test]
    public function reentry_below_min_ticket_usd_produces_no_order(): void
    {
        $this->plugin('smx-pi-tinyreentry', ['reentry' => ['size' => ['mode' => 'formula', 'expr' => 'sold_qty * 0.0000001']]]);
        $ctx = $this->ctx('smx-pi-tinyreentry');
        $p = $this->freshPosition();

        $this->step($p, $ctx, 100.5);   // rung 0 fires
        // Every other reentry gate passes here (same retrace as the other tests) — the ticket
        // itself is a fraction of a cent and must be refused, not booked.
        $d = $this->step($p, $ctx, 100.2);
        $this->assertFalse($d->shouldAdd(), 'size.min_ticket_usd must still clamp a reentry order to nothing');
    }
}
