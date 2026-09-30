<?php

namespace Tests;

use App\Desk\DeskLogin;
use App\Desk\Onboarding\OnboardingWizard;
use App\Desk\Settings;
use App\Desk\Strategies\JsonRuleEvaluator;
use App\Models\Setting;
use App\Services\Indicators\IndicatorCache;
use App\Services\Market\CandleStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

abstract class TestCase extends BaseTestCase
{
    /** The owner's password on a desk that has finished first-login setup. */
    protected const DESK_PASSWORD = 'owner-desk-password';

    /**
     * Most tests exercise a configured desk, so they start signed in as its owner: a hashed
     * password is stored and both the X-Desk-Token header and the browser session carry it.
     * Tests of first-login, fresh-install and login flows set this to false and start bare.
     */
    protected bool $deskPasswordSet = true;

    protected function setUp(): void
    {
        parent::setUp();

        // Blade layouts call @vite; a clean checkout (CI, a fresh clone) has no public/build/manifest.json,
        // and no test asserts on the built asset tags, so render every view without the manifest.
        $this->withoutVite();

        // Tests may themselves run in a container; keyless-setup tests opt back in explicitly.
        config(['desk.in_container' => false]);

        if ($this->deskPasswordSet && in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            $this->signInAsOwner();
        }

        // Live executors pause between order-status polls; no test wants to actually wait.
        config(['desk.live_orders.poll_sleep_ms' => 0]);

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

    protected function signInAsOwner(): void
    {
        Setting::updateOrCreate(['key' => 'master_password'], ['value' => Hash::make(self::DESK_PASSWORD)]);
        Cache::forget('desk:settings');

        $this->withHeader('X-Desk-Token', self::DESK_PASSWORD);
        $this->withSession(['desk_authed' => DeskLogin::fingerprint()]);
    }

    /** Marks the first-run wizard finished, so the root page serves the app instead of redirecting to it. */
    protected function completeOnboarding(): void
    {
        app(Settings::class)->set('onboarding_state', ['steps' => array_fill_keys(OnboardingWizard::ORDER, ['status' => 'done'])]);
    }
}
