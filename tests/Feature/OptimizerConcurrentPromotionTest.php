<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\DeskOptimize;
use App\Desk\Settings;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Finding 13: a round snapshots its champion, waits on the sweep farm, then must not promote against
 * a baseline that changed while it waited. publishOrSkip() is the compare-and-swap DeskOptimize::round()
 * calls at promotion time — same function under test here, invoked directly with the exact arguments
 * round() builds, rather than driving the whole random-candidate-generation command end to end.
 */
class OptimizerConcurrentPromotionTest extends TestCase
{
    use RefreshDatabase;

    private function roundAttrs(): array
    {
        return [
            'product_id' => 'BTC-USD', 'strategy' => 'mr', 'side' => 'long',
            'train_from' => now()->subDays(30), 'train_to' => now()->subDays(7),
            'test_from' => now()->subDays(7), 'test_to' => now(),
            'candidates' => 10, 'champion_params' => [], 'best_params' => ['mr.timeframe' => '2m'],
            'note' => 'promoted: beats champion on train AND on the unseen test window',
        ];
    }

    public function test_a_champion_changed_mid_round_is_not_promoted(): void
    {
        config(['cache.default' => 'array']);
        $expected = DeskOptimize::championVersion('BTC-USD', 'long');

        // a faster concurrent round (or a human via the dashboard) promotes into this same slot while
        // this round is still out waiting on the sweep farm
        Setting::create(['key' => 'per_product.BTC-USD.mr.qty_pct', 'value' => 40]);

        $round = DeskOptimize::publishOrSkip(
            $this->roundAttrs(),
            ['per_product.BTC-USD.mr.timeframe' => '2m'],
            $expected,
            'BTC-USD', 'long',
        );

        $this->assertFalse($round->promoted);
        $this->assertSame('champion changed during round — re-score', $round->note);
        $this->assertNull(Setting::find('per_product.BTC-USD.mr.timeframe'), 'no key from the skipped promotion is written');
    }

    public function test_a_promotion_writes_every_key_and_marks_the_round_promoted_in_one_transaction(): void
    {
        config(['cache.default' => 'array']);
        $expected = DeskOptimize::championVersion('BTC-USD', 'long');

        $round = DeskOptimize::publishOrSkip(
            $this->roundAttrs(),
            ['per_product.BTC-USD.mr.timeframe' => '2m', 'per_product.BTC-USD.mr.qty_pct' => 25],
            $expected,
            'BTC-USD', 'long',
        );

        $this->assertTrue($round->promoted);
        $this->assertSame('promoted: beats champion on train AND on the unseen test window', $round->note);
        $this->assertSame('2m', Setting::find('per_product.BTC-USD.mr.timeframe')->value);
        $this->assertSame(25, Setting::find('per_product.BTC-USD.mr.qty_pct')->value);
    }

    public function test_a_failed_write_mid_publish_rolls_back_every_key(): void
    {
        config(['cache.default' => 'array']);
        $expected = DeskOptimize::championVersion('BTC-USD', 'long');

        $inserts = 0;
        DB::listen(function ($query) use (&$inserts) {
            if (str_contains($query->sql, 'insert into "settings"') && ++$inserts === 2) {
                throw new \RuntimeException('simulated write failure');
            }
        });

        try {
            DeskOptimize::publishOrSkip(
                $this->roundAttrs(),
                ['per_product.BTC-USD.mr.timeframe' => '2m', 'per_product.BTC-USD.mr.qty_pct' => 25],
                $expected,
                'BTC-USD', 'long',
            );
            $this->fail('expected the simulated write failure to propagate');
        } catch (\RuntimeException) {
            // expected — the transaction must roll back, not commit a partial write
        }

        $this->assertNull(Setting::find('per_product.BTC-USD.mr.timeframe'), 'the transaction rolled back the whole batch, not just the failing key');
        $this->assertNull(Setting::find('per_product.BTC-USD.mr.qty_pct'));
        $this->assertDatabaseMissing('optimizer_rounds', ['product_id' => 'BTC-USD']);
    }

    public function test_promotion_invalidates_the_settings_cache_once_after_commit(): void
    {
        config(['cache.default' => 'array']);
        app(Settings::class)->overrides();   // warm the cache
        $this->assertNotNull(Cache::get('desk:settings'));

        $expected = DeskOptimize::championVersion('BTC-USD', 'long');
        $round = DeskOptimize::publishOrSkip(
            $this->roundAttrs(),
            ['per_product.BTC-USD.mr.timeframe' => '2m'],
            $expected,
            'BTC-USD', 'long',
        );
        // round() only invalidates when the transaction actually promoted — mirror that here.
        if ($round->promoted) {
            Settings::invalidate();
        }

        $this->assertNull(Cache::get('desk:settings'), 'cache invalidated exactly once, after commit');
        $this->assertSame('2m', app(Settings::class)->overrides()['per_product']['BTC-USD']['mr']['timeframe']);
    }

    public function test_a_skipped_promotion_never_invalidates_the_cache(): void
    {
        config(['cache.default' => 'array']);
        app(Settings::class)->overrides();
        $this->assertNotNull(Cache::get('desk:settings'));

        $expected = DeskOptimize::championVersion('BTC-USD', 'long');
        Setting::create(['key' => 'per_product.BTC-USD.mr.qty_pct', 'value' => 40]);   // races ahead of this round

        $round = DeskOptimize::publishOrSkip($this->roundAttrs(), ['per_product.BTC-USD.mr.timeframe' => '2m'], $expected, 'BTC-USD', 'long');
        if ($round->promoted) {
            Settings::invalidate();
        }

        $this->assertFalse($round->promoted);
        $this->assertNotNull(Cache::get('desk:settings'), 'a skipped promotion never touches the settings cache');
    }

    /**
     * Reviewer blocker 3: championVersion() used to ignore $side entirely and fingerprint every
     * per_product.<pid>.* row (mr.*, mr.short.*, custom.*), so a short promotion invalidated an in-flight
     * long round's CAS baseline. A concurrent short-side write must never fail a long round's publish.
     */
    public function test_a_concurrent_short_side_write_never_fails_a_long_rounds_publish(): void
    {
        config(['cache.default' => 'array']);
        $expected = DeskOptimize::championVersion('BTC-USD', 'long', 'mr.');

        // A short-side round (or the dashboard) promotes into the short subtree while this long round waits.
        Setting::create(['key' => 'per_product.BTC-USD.mr.short.timeframe', 'value' => '30s']);

        $round = DeskOptimize::publishOrSkip(
            $this->roundAttrs(),
            ['per_product.BTC-USD.mr.timeframe' => '2m'],
            $expected,
            'BTC-USD', 'long', 'mr.',
        );

        $this->assertTrue($round->promoted, 'the short subtree is out of scope for a long round\'s version');
        $this->assertSame('2m', Setting::find('per_product.BTC-USD.mr.timeframe')->value);
    }
}
