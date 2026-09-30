<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Settings;
use App\Events\BacktestScored;
use App\Events\ChampionPromoted;
use App\Events\OptimizerRoundScored;
use App\Models\Setting;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use ReflectionClass;
use Tests\TestCase;

class PrivateOptimizerChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        // Channels register on whichever driver was default at boot; re-register on the reverb one.
        require base_path('routes/channels.php');
    }

    private function auth(): TestResponse
    {
        return $this->post('/broadcasting/auth', ['channel_name' => 'private-optimizer', 'socket_id' => '1234.5678']);
    }

    public function test_unauthenticated_browser_cannot_subscribe_when_a_password_is_set(): void
    {
        $this->flushSession();

        $this->auth()->assertForbidden();
    }

    public function test_logged_in_browser_gets_a_signed_subscription(): void
    {
        $this->flushSession();
        $this->post('/login', ['password' => self::DESK_PASSWORD])->assertRedirect('/');

        $this->auth()->assertOk()->assertJsonStructure(['auth']);
    }

    public function test_logged_in_post_with_the_session_csrf_token_is_signed(): void
    {
        $this->flushSession();
        $this->post('/login', ['password' => self::DESK_PASSWORD]);

        $this->withHeader('X-CSRF-TOKEN', session()->token())
            ->post('/broadcasting/auth', ['channel_name' => 'private-optimizer', 'socket_id' => '1234.5678'])
            ->assertOk()->assertJsonStructure(['auth']);
    }

    public function test_a_stale_login_after_a_password_change_is_refused(): void
    {
        $this->auth()->assertOk();

        app(Settings::class)->setMasterPassword('a-different-desk-password');

        $this->auth()->assertForbidden();
    }

    public function test_a_session_that_only_holds_the_bootstrap_key_is_refused(): void
    {
        Setting::where('key', 'master_password')->delete();
        Cache::forget('desk:settings');
        config(['desk.master_password' => 'i-0123456789abcdef0']);
        $this->flushSession();
        $this->post('/login', ['password' => 'i-0123456789abcdef0'])->assertRedirect('/onboarding');

        $this->auth()->assertForbidden();
    }

    public function test_a_desk_with_no_password_refuses_the_channel(): void
    {
        Setting::where('key', 'master_password')->delete();
        Cache::forget('desk:settings');
        config(['desk.master_password' => '']);
        $this->flushSession();

        $this->auth()->assertForbidden();
    }

    public function test_optimizer_events_broadcast_on_a_private_channel(): void
    {
        foreach ([BacktestScored::class, ChampionPromoted::class, OptimizerRoundScored::class] as $class) {
            $channels = (new ReflectionClass($class))->newInstanceWithoutConstructor()->broadcastOn();

            $this->assertCount(1, $channels, $class);
            $this->assertInstanceOf(PrivateChannel::class, $channels[0], $class);
            $this->assertSame('private-optimizer', $channels[0]->name, $class);
        }
    }

    public function test_no_broadcast_event_uses_a_public_channel(): void
    {
        foreach (glob(app_path('Events/*.php')) as $file) {
            $class = 'App\\Events\\'.basename($file, '.php');
            if (! is_subclass_of($class, ShouldBroadcast::class)) {
                continue;
            }
            foreach ((new ReflectionClass($class))->newInstanceWithoutConstructor()->broadcastOn() as $channel) {
                $this->assertNotSame(Channel::class, $channel::class, "$class broadcasts publicly");
            }
        }
    }
}
