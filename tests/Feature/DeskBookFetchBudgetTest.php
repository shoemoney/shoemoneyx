<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Data\ProductStats;
use App\Desk\Desk;
use App\Exchange\Coinbase\CoinbaseMarketData;
use App\Exchange\Contracts\MarketData;
use App\Models\DeskEvent;
use App\Services\Market\CandleStore;
use App\Services\Market\ProductStatsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fixtures\TwoTicketStrategy;
use Tests\TestCase;

/**
 * Reviewer blocker 5: Desk::cycle() mapped a synchronous, up-to-config('coinbase.timeout') book fetch
 * over EVERY scan candidate before VET, with no bound on the batch's total wall-clock time. Desk now
 * (a) truncates to scan.max_candidates before fetching, defensively, even if a strategy ever returns
 * more, and (b) spends at most desk.scan.book_fetch_budget_seconds fetching books across the whole
 * shortlist — once that budget is gone, the remaining candidates scan without depth rather than
 * stalling the cycle. This is proven with a fake ProductStatsBuilder that counts withBook() calls and
 * takes a small, deterministic amount of real time per call — long enough to blow a tiny configured
 * budget after the first fetch, short enough not to slow the suite.
 */
class DeskBookFetchBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);

        $this->app->bind(MarketData::class, fn () => new class extends CoinbaseMarketData
        {
            public function ticker(string $productId, int $limit = 100): array
            {
                return ['best_bid' => 1.0, 'best_ask' => 1.0, 'trades' => []];
            }

            public function healthy(): bool
            {
                return true;
            }
        });

        app('App\Desk\Settings')->set('paper.slippage_bps', 0);
        app('App\Desk\Settings')->set('fees.taker_rate', 0.0);
        app('App\Desk\Settings')->set('fees.per_contract_usd', 0);
    }

    public function test_book_fetches_stop_once_the_time_budget_is_spent(): void
    {
        // 25ms per call, a 15ms total budget: the first candidate's fetch alone blows the budget, so
        // the second candidate must scan without a book fetch at all.
        config(['desk.scan.book_fetch_budget_seconds' => 0.015]);

        $fakeStats = new class(app(MarketData::class), app(CandleStore::class)) extends ProductStatsBuilder
        {
            public int $bookFetches = 0;

            public function withBook(ProductStats $s): ProductStats
            {
                $this->bookFetches++;
                usleep(25_000);

                return $s;
            }
        };
        $this->app->instance(ProductStatsBuilder::class, $fakeStats);

        config(['desk.strategies.two_ticket_test' => TwoTicketStrategy::class, 'desk.strategy' => 'two_ticket_test']);
        $this->app->instance(TwoTicketStrategy::class, new TwoTicketStrategy('BTC-USD', 100.0, 'ETH-USD', 100.0));

        $run = app(Desk::class)->cycle();

        $this->assertSame('done', $run->status);
        $this->assertSame(2, $run->candidates, 'both candidates still scan and get vetted — only the BOOK fetch is bounded');
        $this->assertSame(1, $fakeStats->bookFetches, 'the second candidate must be skipped once the budget is spent');

        $warning = DeskEvent::where('level', 'warn')->where('message', 'like', '%book-fetch budget%')->latest('id')->first();
        $this->assertNotNull($warning, 'a spent budget must be logged, not silently swallowed');
        $this->assertStringContainsString('1 candidate', $warning->message);
    }

    public function test_book_fetches_are_not_bounded_when_the_budget_is_never_spent(): void
    {
        // The default (real) budget is generous; with a fast fake fetch, both candidates get their book.
        $fakeStats = new class(app(MarketData::class), app(CandleStore::class)) extends ProductStatsBuilder
        {
            public int $bookFetches = 0;

            public function withBook(ProductStats $s): ProductStats
            {
                $this->bookFetches++;

                return $s;
            }
        };
        $this->app->instance(ProductStatsBuilder::class, $fakeStats);

        config(['desk.strategies.two_ticket_test' => TwoTicketStrategy::class, 'desk.strategy' => 'two_ticket_test']);
        $this->app->instance(TwoTicketStrategy::class, new TwoTicketStrategy('BTC-USD', 100.0, 'ETH-USD', 100.0));

        $run = app(Desk::class)->cycle();

        $this->assertSame('done', $run->status);
        $this->assertSame(2, $fakeStats->bookFetches);
    }
}
