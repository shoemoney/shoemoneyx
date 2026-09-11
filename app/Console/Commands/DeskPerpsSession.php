<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Desk\Execution\PerpsCalendar;
use App\Exchange\Coinbase\Api\CoinbaseApiException;
use App\Exchange\Coinbase\CoinbaseMarketData;
use App\Exchange\Coinbase\CoinbasePerpsExecutor;
use Illuminate\Console\Command;

/** Read-only snapshot of the live CFM perps session (margin window, buying power, halt flag). GET-only, no order endpoints. */
class DeskPerpsSession extends Command
{
    protected $signature = 'desk:perps-session
        {--dry-order= : spot product, e.g. BTC-USD}
        {--usd=50}
        {--side=BUY : BUY | SELL}';

    protected $description = 'Live Coinbase US perps (CFM) margin window, buying power and halt flag';

    public function handle(CoinbaseMarketData $market): int
    {
        $executor = app(CoinbasePerpsExecutor::class);

        try {
            $session = $executor->session();
        } catch (CoinbaseApiException $e) {
            $this->line('CDP key rejected or API unavailable: '.substr($e->getMessage(), 0, 120));

            return self::FAILURE;
        }

        $this->table(['field', 'value'], [
            ['margin window', $session->window->value],
            ['window ends at', $session->windowEndsAt?->format(DATE_ATOM) ?? 'unknown'],
            ['futures buying power', number_format($session->futuresBuyingPower, 2)],
            ['initial margin', number_format($session->initialMargin, 2)],
            ['available margin', number_format($session->availableMargin, 2)],
            ['liquidation buffer %', $session->liquidationBufferPct !== null ? number_format($session->liquidationBufferPct, 2) : 'unknown'],
        ]);

        $this->line('halted: '.(PerpsCalendar::halted(now()->toDateTimeImmutable()) ? 'yes' : 'no'));

        $spotPid = $this->option('dry-order');
        if ($spotPid !== null) {
            $this->dryOrder($executor, $market, (string) $spotPid);
        }

        return self::SUCCESS;
    }

    /** Sizes and gates a hypothetical order through plan() only — never calls marketContracts. */
    private function dryOrder(CoinbasePerpsExecutor $executor, CoinbaseMarketData $market, string $spotPid): void
    {
        $usd = (float) $this->option('usd');
        $side = strtoupper((string) $this->option('side'));

        $price = 0.0;
        try {
            $ticker = $market->ticker($spotPid, 1);
            $bid = (float) ($ticker['best_bid'] ?? 0);
            $ask = (float) ($ticker['best_ask'] ?? 0);
            $price = $side === 'SELL' ? ($bid ?: $ask) : ($ask ?: $bid);
        } catch (\Throwable) {
            $price = 0.0;
        }

        $plan = $executor->plan($spotPid, $side, $usd, null, $price, checkMargin: true);

        $this->table(['field', 'value'], [
            ['perp product id', $plan->perpProductId ?? 'n/a'],
            ['contracts', (string) $plan->contracts],
            ['notional', number_format($plan->notional, 2)],
            ['gate', $plan->gate === null ? 'n/a' : ($plan->gate->allowed ? 'allowed' : 'refused: '.$plan->gate->reason)],
        ]);

        if ($plan->rejection === null) {
            $this->line(sprintf('would send: POST /api/v3/brokerage/orders market_ioc %s %dx%s', $side, $plan->contracts, $plan->perpProductId));
        } else {
            $this->line('would reject: '.$plan->rejection);
        }

        $this->line('no order was sent');
    }
}
