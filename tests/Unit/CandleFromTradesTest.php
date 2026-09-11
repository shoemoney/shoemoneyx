<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Candle;
use App\Services\Market\CandleStore;
use App\Exchange\Coinbase\CoinbaseMarketData;
use App\Services\Market\TradeBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CandleFromTradesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_three_new_timeframes_parse_to_seconds(): void
    {
        $this->assertSame(13, Candle::DURATIONS['13s']);
        $this->assertSame(20, Candle::DURATIONS['20s']);
        $this->assertSame(25, Candle::DURATIONS['25s']);
        $this->assertContains('13s', Candle::FROM_TRADES);
        $this->assertContains('20s', Candle::FROM_TRADES);
        $this->assertContains('25s', Candle::FROM_TRADES);
    }

    public function test_a_hand_built_trade_list_buckets_into_the_right_bar_on_all_three_timeframes(): void
    {
        $pid = 'FROM-TRADES-TEST';

        // 1300 is a multiple of 13, 20 and 25, so all three trades land in the bucket
        // starting at 1300 on every new timeframe — one bucket, three durations, one assertion shape.
        // The tape is newest-first (Coinbase's own trade order): the first trade sets close,
        // the last (chronologically earliest) trade ends up as open.
        $trades = [
            ['trade_id' => '3', 'time' => 1310, 'price' => 105.0, 'size' => 1.0, 'side' => 'BUY'],
            ['trade_id' => '2', 'time' => 1305, 'price' => 98.0, 'size' => 2.0, 'side' => 'SELL'],
            ['trade_id' => '1', 'time' => 1300, 'price' => 100.0, 'size' => 3.0, 'side' => 'BUY'],
        ];

        $market = $this->createMock(CoinbaseMarketData::class);
        $market->expects($this->exactly(2))
            ->method('trades')
            ->willReturnOnConsecutiveCalls($trades, []);

        $store = new CandleStore($market);
        $bf = new TradeBackfill($market, $store);

        $r = $bf->run($pid, fromUnix: 1000, toUnix: 2000, timeframes: ['13s', '20s', '25s']);

        $this->assertSame(3, $r['trades']);
        $this->assertSame(1300, $r['earliest']);

        foreach (['13s', '20s', '25s'] as $tf) {
            $bar = Candle::for($pid, $tf)->where('candle_start', gmdate('Y-m-d H:i:s', 1300))->first();
            $this->assertNotNull($bar, "expected a {$tf} bar starting at 1300");
            $this->assertSame(100.0, $bar->open, "{$tf} open");
            $this->assertSame(105.0, $bar->high, "{$tf} high");
            $this->assertSame(98.0, $bar->low, "{$tf} low");
            $this->assertSame(105.0, $bar->close, "{$tf} close");
            $this->assertSame(6.0, $bar->volume, "{$tf} volume");
        }
    }
}
