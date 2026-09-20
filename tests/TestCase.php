<?php

namespace Tests;

use App\Desk\Strategies\JsonRuleEvaluator;
use App\Services\Indicators\IndicatorCache;
use App\Services\Market\CandleStore;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Blade layouts call @vite; a clean checkout (CI, a fresh clone) has no public/build/manifest.json,
        // and no test asserts on the built asset tags, so render every view without the manifest.
        $this->withoutVite();

        // CandleStore's in-process candle memo lives outside the container, so it survives the app
        // rebuild between test methods; without this, two tests proposing the same (product, timeframe,
        // window) -- easy to do with a shared fixture date -- can see each other's candles.
        CandleStore::forgetLocal();

        // Same reasoning as CandleStore::forgetLocal() above: IndicatorCache's memo also lives
        // outside the container.
        IndicatorCache::forgetAll();

        // Same reasoning again: JsonRuleEvaluator's "between" legacy-value warning is deduped
        // per (strategy key, rule) for the life of the PHP process, so two tests asserting on it
        // with the same field/value would otherwise see each other's dedupe state.
        JsonRuleEvaluator::forgetLoggedBetweenWarnings();
    }
}
