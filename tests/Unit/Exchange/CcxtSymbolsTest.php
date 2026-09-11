<?php

declare(strict_types=1);

namespace Tests\Unit\Exchange;

use App\Exchange\Ccxt\Symbols;
use Tests\TestCase;

class CcxtSymbolsTest extends TestCase
{
    public function test_single_char_quote_round_trips(): void
    {
        $this->assertSame('BTC/USD', Symbols::toCcxt('BTC-USD'));
        $this->assertSame('BTC-USD', Symbols::fromCcxt('BTC/USD'));
    }

    public function test_multi_char_quote_round_trips(): void
    {
        $this->assertSame('ETH/USDT', Symbols::toCcxt('ETH-USDT'));
        $this->assertSame('ETH-USDT', Symbols::fromCcxt('ETH/USDT'));

        $this->assertSame('SOL/USDC', Symbols::toCcxt('SOL-USDC'));
        $this->assertSame('SOL-USDC', Symbols::fromCcxt('SOL/USDC'));

        $this->assertSame('MATIC/EUR', Symbols::toCcxt('MATIC-EUR'));
        $this->assertSame('MATIC-EUR', Symbols::fromCcxt('MATIC/EUR'));
    }

    public function test_only_the_first_separator_is_swapped(): void
    {
        $this->assertSame('1INCH/USD-PERP', Symbols::toCcxt('1INCH-USD-PERP'));
        $this->assertSame('BTC-USD:USD', Symbols::fromCcxt('BTC/USD:USD'));
    }

    public function test_value_without_a_separator_is_unchanged(): void
    {
        $this->assertSame('BTCUSD', Symbols::toCcxt('BTCUSD'));
        $this->assertSame('BTCUSD', Symbols::fromCcxt('BTCUSD'));
    }
}
