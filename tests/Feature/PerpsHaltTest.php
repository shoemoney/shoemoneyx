<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Data\Candidate as CandidateRow;
use App\Desk\Data\ProductStats;
use App\Desk\Data\SizeDecision;
use App\Desk\Data\Verdict;
use App\Desk\Desk;
use App\Desk\DeskContext;
use App\Desk\Execution\PaperExecutor;
use App\Models\Candidate;
use App\Models\DeskRun;
use App\Models\Fill;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PerpsHaltTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper', 'desk.perps.enabled' => true, 'desk.paper.starting_cash' => 10000]);
        Http::fake(['api.coinbase.com/*' => Http::response([])]);
    }

    public function test_enter_writes_a_halt_fill_and_opens_nothing_during_the_weekly_maintenance_window(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-09-04T21:30:00Z')); // Friday 17:30 ET

        $stats = new ProductStats(
            productId: 'BTC-USD',
            price: 80000.0,
            bestBid: 79990.0,
            bestAsk: 80010.0,
            ageHours: null,
            volumeM5Usd: 0.0,
            volumeH1Usd: 0.0,
            volumeH6Usd: 0.0,
            volumeH24Usd: 0.0,
            volumePrevH24Usd: 0.0,
            priceChangeM5Pct: 0.0,
            priceChangeH1Pct: 0.0,
            priceChangeH6Pct: 0.0,
            priceChangeH24Pct: 0.0,
            buysH1: 0,
            sellsH1: 0,
            buysM5: 0,
            sellsM5: 0,
            buyVolumeH1Usd: 0.0,
            sellVolumeH1Usd: 0.0,
            spreadBps: null,
            bookDepthUsd: null,
            candlesH1Count: 1,
        );
        $candidateRow = new CandidateRow($stats, 1.0, 'test', 1);
        $verdict = Verdict::pass($candidateRow, ['test']);
        $size = new SizeDecision($verdict, 500.0, 0.1, 0.05, true, false, 'test');
        $ctx = new DeskContext([], 'paper');

        $run = DeskRun::create(['mode' => 'paper', 'strategy' => 'mr', 'degraded' => false, 'started_at' => now()]);
        $row = Candidate::create([
            'desk_run_id' => $run->id,
            'product_id' => 'BTC-USD',
            'rank' => 1,
            'rank_reason' => 'test',
            'score' => 1.0,
            'metrics' => $stats->jsonSerialize(),
        ]);

        $desk = app(Desk::class);
        $executor = app(PaperExecutor::class);
        $enter = new \ReflectionMethod(Desk::class, 'enter');
        $enter->setAccessible(true);
        $fill = $enter->invoke($desk, $executor, $size, $ctx, $run, $row);

        $this->assertNull($fill);
        $this->assertSame(1, Fill::where('status', 'halt')->count());
        $this->assertSame(0, Position::count());
    }
}
