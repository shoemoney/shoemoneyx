<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\DeskOptimize;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding 13: a round must be able to tell whether its coin/side's champion settings changed
 * underneath it. championVersion() is the fingerprint DeskOptimize::round() captures at round start
 * and re-checks at promotion time (see publishOrSkip()).
 */
class DeskOptimizeChampionVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_version_is_stable_when_nothing_changes(): void
    {
        Setting::create(['key' => 'per_product.BTC-USD.mr.timeframe', 'value' => '2m']);

        $v1 = DeskOptimize::championVersion('BTC-USD', 'long');
        $v2 = DeskOptimize::championVersion('BTC-USD', 'long');

        $this->assertSame($v1, $v2);
    }

    public function test_version_changes_when_a_champion_key_is_written(): void
    {
        Setting::create(['key' => 'per_product.BTC-USD.mr.timeframe', 'value' => '2m']);
        $before = DeskOptimize::championVersion('BTC-USD', 'long');

        Setting::updateOrCreate(['key' => 'per_product.BTC-USD.mr.timeframe'], ['value' => '3m']);

        $this->assertNotSame($before, DeskOptimize::championVersion('BTC-USD', 'long'));
    }

    public function test_version_changes_when_a_new_champion_key_is_added(): void
    {
        $before = DeskOptimize::championVersion('BTC-USD', 'long');

        Setting::create(['key' => 'per_product.BTC-USD.mr.qty_pct', 'value' => 20]);

        $this->assertNotSame($before, DeskOptimize::championVersion('BTC-USD', 'long'));
    }

    public function test_version_is_scoped_to_the_coin(): void
    {
        Setting::create(['key' => 'per_product.BTC-USD.mr.timeframe', 'value' => '2m']);
        $btc = DeskOptimize::championVersion('BTC-USD', 'long');

        Setting::create(['key' => 'per_product.ETH-USD.mr.timeframe', 'value' => '5m']);

        $this->assertSame($btc, DeskOptimize::championVersion('BTC-USD', 'long'), 'another coin\'s settings never change this coin\'s version');
    }

    /**
     * Reviewer blocker 3: championVersion($pid, $side) used to ignore $side entirely and fingerprint
     * every per_product.<pid>.* row regardless of namespace, so a short-side promotion (or any
     * Settings-UI save under mr.short.*) invalidated an in-flight long round's CAS baseline, and vice
     * versa. long and short must each see only their own subtree.
     */
    public function test_a_short_side_write_never_changes_the_long_side_version_and_vice_versa(): void
    {
        Setting::create(['key' => 'per_product.BTC-USD.mr.timeframe', 'value' => '2m']);
        $long = DeskOptimize::championVersion('BTC-USD', 'long', 'mr.');
        $short = DeskOptimize::championVersion('BTC-USD', 'short', 'mr.');

        Setting::create(['key' => 'per_product.BTC-USD.mr.short.timeframe', 'value' => '30s']);

        $this->assertSame($long, DeskOptimize::championVersion('BTC-USD', 'long', 'mr.'), 'a short-side write must not change the long-side version');
        $this->assertNotSame($short, DeskOptimize::championVersion('BTC-USD', 'short', 'mr.'), 'the short-side version must itself react to a short-side write');

        $shortAfter = DeskOptimize::championVersion('BTC-USD', 'short', 'mr.');
        Setting::updateOrCreate(['key' => 'per_product.BTC-USD.mr.timeframe'], ['value' => '3m']);

        $this->assertSame($shortAfter, DeskOptimize::championVersion('BTC-USD', 'short', 'mr.'), 'a long-side write must not change the short-side version');
    }

    /** Reviewer blocker 3: an unrelated strategy's settings (e.g. custom.*) must never invalidate mr.*'s version. */
    public function test_an_unrelated_strategys_write_never_changes_this_strategys_version(): void
    {
        Setting::create(['key' => 'per_product.BTC-USD.mr.timeframe', 'value' => '2m']);
        $before = DeskOptimize::championVersion('BTC-USD', 'long', 'mr.');

        Setting::create(['key' => 'per_product.BTC-USD.custom.timeframe', 'value' => '5m']);

        $this->assertSame($before, DeskOptimize::championVersion('BTC-USD', 'long', 'mr.'), 'custom.* is a different strategy prefix entirely');
    }
}
