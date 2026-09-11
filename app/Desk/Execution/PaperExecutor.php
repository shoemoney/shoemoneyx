<?php

declare(strict_types=1);

namespace App\Desk\Execution;

use App\Desk\Settings;
use App\Exchange\Contracts\MarketData;
use App\Models\ArenaSeat;
use App\Models\PaperLedger;
use App\Models\Position;
use Illuminate\Support\Facades\Cache;

/**
 * Simulates market orders against the live best bid/ask with configurable
 * slippage and the taker fee. Cash lives in `paper_ledger`.
 *
 * Perps mode (desk.perps.enabled): prices still come from the spot tape (the perp tracks the
 * index within a few bps) and fees use the per-contract floor. With perps.paper_margin on
 * (the default), longs and shorts post initial margin like CFM does, keyed off the overnight
 * rate; off restores the old cash-notional long / 1x-collateral short paths.
 */
class PaperExecutor implements Executor
{
    /** Set only through withSeat() — null means the main desk's own paper account. */
    private ?int $arenaSeatId = null;

    public function __construct(
        private MarketData $market,
        private Settings $settings,
    ) {}

    public function mode(): string
    {
        return 'paper';
    }

    /** A clone scoped to one arena seat's own paper_ledger rows — every cash movement it makes carries $seatId instead of the main desk's null. */
    public function withSeat(?int $seatId): static
    {
        $clone = clone $this;
        $clone->arenaSeatId = $seatId;

        return $clone;
    }

    public function cash(): float
    {
        if (PaperLedger::seat($this->arenaSeatId)->count() > 0) {
            return (float) PaperLedger::seat($this->arenaSeatId)->sum('amount');
        }

        // Concurrent first callers can both observe an empty ledger; lock and re-check before seeding
        // the initial deposit so the account is never funded twice. One lock key per seat: seats seed
        // independently and never contend with each other or the main desk's own deposit.
        $lockKey = $this->arenaSeatId === null ? 'desk:mutate:paper:deposit' : "desk:mutate:paper:deposit:seat:{$this->arenaSeatId}";

        return Cache::lock($lockKey, 10)->block(5, function () {
            if (PaperLedger::seat($this->arenaSeatId)->count() > 0) {
                return (float) PaperLedger::seat($this->arenaSeatId)->sum('amount');
            }
            $start = (float) $this->startingCash();
            PaperLedger::create(['arena_seat_id' => $this->arenaSeatId, 'kind' => 'deposit', 'amount' => $start, 'note' => 'initial paper deposit']);

            return $start;
        });
    }

    /** A seat's own starting_cash when scoped, config/settings default otherwise. */
    private function startingCash(): float
    {
        if ($this->arenaSeatId !== null) {
            $seat = ArenaSeat::find($this->arenaSeatId);
            if ($seat !== null) {
                return (float) $seat->starting_cash;
            }
        }

        return (float) $this->settings->get('paper.starting_cash', 1000);
    }

    /**
     * A legacy fractional holding is a position that is not itself a whole number of contracts —
     * opened before whole-contract sizing existed — not merely a trim request that happens to be
     * fractional. Only one open position per product/mode exists at a time, so the row is looked up
     * directly rather than threaded through the Executor interface (which every venue shares).
     */
    private function legacyFractional(string $productId, ?array $spec): bool
    {
        if ($spec === null) {
            return false;
        }
        $position = Position::open()->mode($this->mode())->seat($this->arenaSeatId)->where('product_id', $productId)->first();
        if ($position === null) {
            return false;
        }
        if (array_key_exists('fractional', $position->meta ?? [])) {
            return (bool) $position->meta['fractional'];
        }
        $qty = (float) $position->quantity;

        return abs($qty - round($qty / $spec['contract_size']) * $spec['contract_size']) > 1e-9 * max(1.0, $qty);
    }

    /** Perps enabled and the settings/config flag says to size fills in whole nano contracts. Spot never does. */
    private function wholeContracts(): bool
    {
        return Perps::enabled() && (bool) $this->settings->get('perps.whole_contracts', config('desk.perps.whole_contracts'));
    }

    /** Perps enabled and the settings/config flag says to post margin instead of the old cash-notional / 1x-collateral paths. */
    private function marginEnabled(): bool
    {
        return Perps::enabled() && (bool) $this->settings->get('perps.paper_margin', config('desk.perps.paper_margin'));
    }

    /** This account is never on intraday margin: every paper position posts the overnight rate. */
    private function initialRate(): float
    {
        return MarginWindow::Overnight->marginRate();
    }

    public function buy(string $productId, float $usd, float $decisionPrice): OrderResult
    {
        $t = $this->market->ticker($productId, 1);
        $ask = $t['best_ask'] > 0 ? $t['best_ask'] : $decisionPrice;
        $slip = (float) $this->settings->get('paper.slippage_bps', 15) / 10_000;
        $fillPrice = $ask * (1 + $slip);
        $rate = (float) $this->settings->get('fees.taker_rate', 0.006);
        $perContract = (float) $this->settings->get('fees.per_contract_usd', 0);
        $cash = $this->cash();

        if ($this->marginEnabled()) {
            if ($this->wholeContracts()) {
                $lot = Lot::forUsd($productId, $usd, $fillPrice, $rate, $perContract, true);
                if ($lot === null) {
                    return OrderResult::rejected('rejected', $usd, $decisionPrice, 'under one contract');
                }
                $notional = $lot->notional;
                $qty = $lot->qty;
                $feeUsd = $lot->feeUsd;
            } else {
                if ($usd <= 0) {
                    return OrderResult::rejected('rejected', $usd, $decisionPrice, 'zero ticket');
                }
                $notional = $usd;
                $qty = $usd / $fillPrice;
                $feeUsd = Lot::fee($usd, 0, $rate, $perContract);
            }
            $margin = $notional * $this->initialRate();
            if ($margin + $feeUsd > $cash + 0.0001) {
                return OrderResult::rejected('rejected', $usd, $decisionPrice, sprintf('insufficient margin: need $%.2f, cash $%.2f', $margin + $feeUsd, $cash));
            }

            PaperLedger::create(['arena_seat_id' => $this->arenaSeatId, 'kind' => 'buy', 'amount' => -($margin + $feeUsd), 'ref' => $productId, 'note' => sprintf('MARGIN %.8f @ %.6f', $qty, $fillPrice)]);

            return new OrderResult('filled', $usd, $notional, $qty, $decisionPrice, $fillPrice, $feeUsd, false, 'paper-'.uniqid(), ['margin' => $margin, 'ask' => $ask, 'best_bid' => $t['best_bid'], 'best_ask' => $ask]);
        }

        if ($usd > $cash + 0.0001) {
            return OrderResult::rejected('rejected', $usd, $decisionPrice, sprintf('insufficient paper cash $%.2f', $cash));
        }

        if ($this->wholeContracts()) {
            $lot = Lot::forUsd($productId, $usd, $fillPrice, $rate, $perContract, true);
            if ($lot === null) {
                return OrderResult::rejected('rejected', $usd, $decisionPrice, 'under one contract');
            }

            PaperLedger::create(['arena_seat_id' => $this->arenaSeatId, 'kind' => 'buy', 'amount' => -($lot->notional + $lot->feeUsd), 'ref' => $productId, 'note' => sprintf('%.8f @ %.6f', $lot->qty, $fillPrice)]);

            return new OrderResult('filled', $usd, $lot->notional, $lot->qty, $decisionPrice, $fillPrice, $lot->feeUsd, false, 'paper-'.uniqid(), ['ask' => $ask, 'best_bid' => $t['best_bid'], 'best_ask' => $ask]);
        }

        $feeUsd = Lot::fee($usd, 0, $rate, $perContract);
        $qty = ($usd - $feeUsd) / $fillPrice;

        PaperLedger::create(['arena_seat_id' => $this->arenaSeatId, 'kind' => 'buy', 'amount' => -$usd, 'ref' => $productId, 'note' => sprintf('%.8f @ %.6f', $qty, $fillPrice)]);

        return new OrderResult('filled', $usd, $usd, $qty, $decisionPrice, $fillPrice, $feeUsd, false, 'paper-'.uniqid(), ['ask' => $ask, 'best_bid' => $t['best_bid'], 'best_ask' => $ask]);
    }

    public function sell(string $productId, float $qty, float $decisionPrice, float $entryUsdShare = 0.0, bool $maker = false): OrderResult
    {
        $t = $this->market->ticker($productId, 1);
        $bid = $t['best_bid'] > 0 ? $t['best_bid'] : $decisionPrice;
        $slip = (float) $this->settings->get('paper.slippage_bps', 15) / 10_000;
        $fillPrice = $bid * (1 - $slip);
        $rate = (float) $this->settings->get($maker ? 'fees.maker_rate' : 'fees.taker_rate', $maker ? 0.004 : 0.006);
        $perContract = (float) $this->settings->get('fees.per_contract_usd', 0);

        $wholeContracts = $this->wholeContracts();
        $spec = $wholeContracts ? Perps::spec($productId) : null;
        $lot = Lot::forQty($productId, $qty, $fillPrice, $rate, $perContract, $wholeContracts, $this->legacyFractional($productId, $spec));

        if ($this->marginEnabled()) {
            $marginBack = $entryUsdShare * $this->initialRate();
            $net = $marginBack + ($lot->notional - $entryUsdShare) - $lot->feeUsd;

            // Deferred: the caller (Desk::close()/trim()) runs this inside its own DB transaction so a
            // rollback there undoes this cash movement too, instead of moving cash outside the boundary.
            $ledgerWrite = fn () => PaperLedger::create(['arena_seat_id' => $this->arenaSeatId, 'kind' => 'sell', 'amount' => $net, 'ref' => $productId, 'note' => sprintf('MARGIN %.8f @ %.6f', $lot->qty, $fillPrice)]);

            return new OrderResult('filled', $lot->notional, $lot->notional, $lot->qty, $decisionPrice, $fillPrice, $lot->feeUsd, false, 'paper-'.uniqid(), ['bid' => $bid, 'best_bid' => $bid, 'best_ask' => $t['best_ask']], ledgerWrite: $ledgerWrite);
        }

        $net = $lot->notional - $lot->feeUsd;
        $ledgerWrite = fn () => PaperLedger::create(['arena_seat_id' => $this->arenaSeatId, 'kind' => 'sell', 'amount' => $net, 'ref' => $productId, 'note' => sprintf('%.8f @ %.6f', $lot->qty, $fillPrice)]);

        return new OrderResult('filled', $lot->notional, $net, $lot->qty, $decisionPrice, $fillPrice, $lot->feeUsd, false, 'paper-'.uniqid(), ['bid' => $bid, 'best_bid' => $bid, 'best_ask' => $t['best_ask']], ledgerWrite: $ledgerWrite);
    }

    public function openShort(string $productId, float $usd, float $decisionPrice): OrderResult
    {
        if (! Perps::enabled() || Perps::spec($productId) === null) {
            return OrderResult::rejected('rejected', $usd, $decisionPrice, 'shorts need desk.perps.enabled and a perps.map entry for '.$productId);
        }
        $t = $this->market->ticker($productId, 1);
        $bid = $t['best_bid'] > 0 ? $t['best_bid'] : $decisionPrice;
        $slip = (float) $this->settings->get('paper.slippage_bps', 15) / 10_000;
        $fillPrice = $bid * (1 - $slip);          // we hit the bid
        $rate = (float) $this->settings->get('fees.taker_rate', 0.006);
        $perContract = (float) $this->settings->get('fees.per_contract_usd', 0);
        $cash = $this->cash();

        if ($this->marginEnabled()) {
            if ($this->wholeContracts()) {
                $lot = Lot::forUsd($productId, $usd, $fillPrice, $rate, $perContract, true);
                if ($lot === null) {
                    return OrderResult::rejected('rejected', $usd, $decisionPrice, 'under one contract');
                }
                $notional = $lot->notional;
                $qty = $lot->qty;
                $feeUsd = $lot->feeUsd;
            } else {
                if ($usd <= 0) {
                    return OrderResult::rejected('rejected', $usd, $decisionPrice, 'zero ticket');
                }
                $notional = $usd;
                $qty = $usd / $fillPrice;
                $feeUsd = Lot::fee($usd, 0, $rate, $perContract);
            }
            $margin = $notional * $this->initialRate();
            if ($margin + $feeUsd > $cash + 0.0001) {
                return OrderResult::rejected('rejected', $usd, $decisionPrice, sprintf('insufficient margin: need $%.2f, cash $%.2f', $margin + $feeUsd, $cash));
            }

            PaperLedger::create(['arena_seat_id' => $this->arenaSeatId, 'kind' => 'short', 'amount' => -($margin + $feeUsd), 'ref' => $productId, 'note' => sprintf('MARGIN SHORT %.8f @ %.6f', $qty, $fillPrice)]);

            return new OrderResult('filled', $usd, $notional, $qty, $decisionPrice, $fillPrice, $feeUsd, false, 'paper-'.uniqid(), ['margin' => $margin, 'bid' => $bid, 'best_bid' => $bid, 'best_ask' => $t['best_ask']]);
        }

        if ($usd > $cash + 0.0001) {
            return OrderResult::rejected('rejected', $usd, $decisionPrice, sprintf('insufficient paper collateral $%.2f', $cash));
        }

        if ($this->wholeContracts()) {
            $lot = Lot::forUsd($productId, $usd, $fillPrice, $rate, $perContract, true);
            if ($lot === null) {
                return OrderResult::rejected('rejected', $usd, $decisionPrice, 'under one contract');
            }

            // 1× margin model: post the lot's notional as margin, proceeds stay locked at the venue, fee is gone for good.
            PaperLedger::create(['arena_seat_id' => $this->arenaSeatId, 'kind' => 'short', 'amount' => -($lot->notional + $lot->feeUsd), 'ref' => $productId, 'note' => sprintf('SHORT %.8f @ %.6f', $lot->qty, $fillPrice)]);

            return new OrderResult('filled', $usd, $lot->notional, $lot->qty, $decisionPrice, $fillPrice, $lot->feeUsd, false, 'paper-'.uniqid(), ['bid' => $bid, 'best_bid' => $bid, 'best_ask' => $t['best_ask']]);
        }

        $feeUsd = Lot::fee($usd, 0, $rate, $perContract);
        $qty = $usd / $fillPrice;                  // notional sold = gross proceeds = the short's entry_usd

        // 1× margin model: post $usd of cash as margin, proceeds stay locked at the venue, fee is gone for good.
        PaperLedger::create(['arena_seat_id' => $this->arenaSeatId, 'kind' => 'short', 'amount' => -($usd + $feeUsd), 'ref' => $productId, 'note' => sprintf('SHORT %.8f @ %.6f', $qty, $fillPrice)]);

        return new OrderResult('filled', $usd, $usd, $qty, $decisionPrice, $fillPrice, $feeUsd, false, 'paper-'.uniqid(), ['bid' => $bid, 'best_bid' => $bid, 'best_ask' => $t['best_ask']]);
    }

    public function coverShort(string $productId, float $qty, float $decisionPrice, float $entryUsdShare, bool $maker = false): OrderResult
    {
        $t = $this->market->ticker($productId, 1);
        $ask = $t['best_ask'] > 0 ? $t['best_ask'] : $decisionPrice;
        $slip = (float) $this->settings->get('paper.slippage_bps', 15) / 10_000;
        $fillPrice = $ask * (1 + $slip);          // we lift the ask
        $rate = (float) $this->settings->get($maker ? 'fees.maker_rate' : 'fees.taker_rate', $maker ? 0.004 : 0.006);
        $perContract = (float) $this->settings->get('fees.per_contract_usd', 0);

        $wholeContracts = $this->wholeContracts();
        $spec = $wholeContracts ? Perps::spec($productId) : null;
        $lot = Lot::forQty($productId, $qty, $fillPrice, $rate, $perContract, $wholeContracts, $this->legacyFractional($productId, $spec));
        $cost = $lot->notional + $lot->feeUsd;      // the short's exit_usd

        if ($this->marginEnabled()) {
            $marginBack = $entryUsdShare * $this->initialRate();
            $amount = $marginBack + ($entryUsdShare - $lot->notional) - $lot->feeUsd;

            // Deferred: see sell() above — the caller writes this inside its own DB transaction.
            $ledgerWrite = fn () => PaperLedger::create(['arena_seat_id' => $this->arenaSeatId, 'kind' => 'cover', 'amount' => $amount, 'ref' => $productId, 'note' => sprintf('MARGIN COVER %.8f @ %.6f', $lot->qty, $fillPrice)]);

            return new OrderResult('filled', $lot->notional, $cost, $lot->qty, $decisionPrice, $fillPrice, $lot->feeUsd, false, 'paper-'.uniqid(), ['ask' => $ask, 'best_bid' => $t['best_bid'], 'best_ask' => $ask], ledgerWrite: $ledgerWrite);
        }

        // margin back + locked proceeds back − buyback cost (entryUsdShare = gross proceeds being closed).
        $ledgerWrite = fn () => PaperLedger::create(['arena_seat_id' => $this->arenaSeatId, 'kind' => 'cover', 'amount' => 2 * $entryUsdShare - $cost, 'ref' => $productId, 'note' => sprintf('COVER %.8f @ %.6f', $lot->qty, $fillPrice)]);

        return new OrderResult('filled', $lot->notional, $cost, $lot->qty, $decisionPrice, $fillPrice, $lot->feeUsd, false, 'paper-'.uniqid(), ['ask' => $ask, 'best_bid' => $t['best_bid'], 'best_ask' => $ask], ledgerWrite: $ledgerWrite);
    }
}
