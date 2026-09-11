<?php

namespace Tests;

use App\Services\Market\CandleStore;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // CandleStore's in-process candle memo lives outside the container, so it survives the app
        // rebuild between test methods; without this, two tests proposing the same (product, timeframe,
        // window) -- easy to do with a shared fixture date -- can see each other's candles.
        CandleStore::forgetLocal();
    }
}
