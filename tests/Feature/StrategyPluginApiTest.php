<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Assist\SmxPrompt;
use App\Jobs\RunBacktest;
use App\Models\Backtest;
use App\Models\StrategyPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StrategyPluginApiTest extends TestCase
{
    use RefreshDatabase;

    private function definition(): array
    {
        return [
            'key' => 'rsi-dip',
            'name' => 'RSI Dip',
            'version' => 1,
            'suggest' => ['products' => ['BTC-USD'], 'days' => 10, 'cash' => 500],
            'scan' => ['filters' => [['field' => 'indicators.rsi14', 'op' => '<', 'value' => 35]]],
        ];
    }

    public function test_validate_accepts_and_rejects(): void
    {
        $this->postJson('/api/strategy-plugins/validate', ['definition' => $this->definition()])
            ->assertOk()->assertJson(['valid' => true]);

        $bad = $this->definition();
        $bad['key'] = 'NOPE';
        $this->postJson('/api/strategy-plugins/validate', ['definition' => $bad])
            ->assertOk()->assertJson(['valid' => false]);
    }

    public function test_store_saves_and_upserts_by_key(): void
    {
        $this->postJson('/api/strategy-plugins', ['definition' => $this->definition()])->assertCreated();
        $this->postJson('/api/strategy-plugins', ['definition' => $this->definition()])->assertCreated();

        $this->assertSame(1, StrategyPlugin::where('key', 'rsi-dip')->count());
    }

    public function test_store_rejects_invalid_with_422(): void
    {
        $bad = $this->definition();
        unset($bad['name']);
        $this->postJson('/api/strategy-plugins', ['definition' => $bad])->assertStatus(422);
    }

    public function test_assist_requires_a_key(): void
    {
        config()->set('services.openrouter.key', null);
        $this->postJson('/api/strategy-assist', [
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ])->assertStatus(401);
    }

    public function test_system_prompt_is_substantive(): void
    {
        $prompt = SmxPrompt::system();
        $this->assertStringContainsString('educational research', $prompt);
        $this->assertStringContainsString('```json', $prompt);
        $this->assertStringContainsString('suggest', $prompt);
        $this->assertGreaterThan(2000, strlen($prompt));
    }

    public function test_suggest_must_be_well_formed(): void
    {
        $bad = $this->definition();
        $bad['suggest'] = ['days' => 9999];
        $this->postJson('/api/strategy-plugins/validate', ['definition' => $bad])
            ->assertOk()->assertJson(['valid' => false]);
    }

    public function test_backtest_trigger_uses_suggest_defaults(): void
    {
        Bus::fake([RunBacktest::class]);
        $plugin = StrategyPlugin::create([
            'key' => 'rsi-dip',
            'name' => 'RSI Dip',
            'definition' => $this->definition(),
        ]);

        $bt = $this->postJson("/api/strategy-plugins/{$plugin->id}/backtest", [])->assertStatus(202)->json();

        $this->assertSame('json', $bt['strategy']);
        $this->assertSame(['BTC-USD'], $bt['products']);
        $this->assertSame(500.0, (float) $bt['starting_cash']);
        $row = Backtest::findOrFail($bt['id']);
        $this->assertSame('rsi-dip', $row->params['json.plugin_key']);
    }

    public function test_models_lists_only_free(): void
    {
        Http::fake(['openrouter.ai/api/v1/models' => Http::response(['data' => [
            ['id' => 'free-a', 'name' => 'Free A', 'pricing' => ['prompt' => '0', 'completion' => '0']],
            ['id' => 'paid-b', 'name' => 'Paid B', 'pricing' => ['prompt' => '0.00001', 'completion' => '0']],
        ]])]);

        $data = $this->get('/api/strategy-models')->assertOk()->json('data');

        $this->assertSame([['id' => 'free-a', 'name' => 'Free A']], $data);
    }

    public function test_export_formats_download(): void
    {
        $plugin = StrategyPlugin::create([
            'key' => 'rsi-dip',
            'name' => 'RSI Dip',
            'definition' => $this->definition(),
        ]);

        $this->get("/api/strategy-plugins/{$plugin->id}/export/pine")->assertOk();
        $this->get("/api/strategy-plugins/{$plugin->id}/export/md")->assertOk();
        $this->get("/api/strategy-plugins/{$plugin->id}/export/json")->assertOk();
        $this->get("/api/strategy-plugins/{$plugin->id}/export/bogus")->assertStatus(404);
    }
}
