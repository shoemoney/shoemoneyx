<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiModelsApiTest extends TestCase
{
    use RefreshDatabase;

    private function fakeCatalogue(): void
    {
        Http::fake(['openrouter.ai/api/v1/models' => Http::response(['data' => [
            ['id' => 'zed/free-two', 'name' => 'Zed Free Two', 'pricing' => ['prompt' => '0', 'completion' => '0']],
            ['id' => 'acme/free-one', 'name' => 'Acme Free One', 'pricing' => ['prompt' => '0', 'completion' => '0']],
            ['id' => 'acme/paid', 'name' => 'Acme Paid', 'pricing' => ['prompt' => '0.0000012', 'completion' => '0.000004']],
            ['id' => 'acme/half-free', 'name' => 'Acme Half Free', 'pricing' => ['prompt' => '0', 'completion' => '0.000004']],
            ['id' => 'deepseek/deepseek-v4-flash', 'name' => 'DeepSeek V4 Flash', 'pricing' => ['prompt' => '0.00000007', 'completion' => '0.0000003']],
        ]])]);
    }

    public function test_free_holds_only_zero_priced_models_plus_the_free_router(): void
    {
        $this->fakeCatalogue();

        $free = $this->getJson('/api/ai/models')->assertOk()->json('free');

        $this->assertSame(['openrouter/free', 'acme/free-one', 'zed/free-two'], array_column($free, 'id'));
        $this->assertSame('Acme Free One', $free[1]['name']);
        $this->assertSame(['prompt' => '0', 'completion' => '0'], $free[1]['pricing']);
        $this->assertNull($free[0]['pricing']);
    }

    public function test_recommended_is_exactly_the_configured_list_with_live_pricing_where_known(): void
    {
        $this->fakeCatalogue();

        $recommended = $this->getJson('/api/ai/models')->assertOk()->json('recommended');

        $this->assertSame(config('ai.recommended'), array_column($recommended, 'id'));
        $this->assertSame(
            ['id' => 'deepseek/deepseek-v4-flash-vision-exp', 'name' => 'deepseek/deepseek-v4-flash-vision-exp', 'pricing' => null],
            $recommended[0],
        );
        $this->assertSame('DeepSeek V4 Flash', $recommended[1]['name']);
        $this->assertSame(['prompt' => '0.00000007', 'completion' => '0.0000003'], $recommended[1]['pricing']);
    }

    public function test_the_free_router_is_not_duplicated_when_openrouter_lists_it(): void
    {
        Http::fake(['openrouter.ai/api/v1/models' => Http::response(['data' => [
            ['id' => 'openrouter/free', 'name' => 'Auto Free Router', 'pricing' => ['prompt' => '0', 'completion' => '0']],
        ]])]);

        $free = $this->getJson('/api/ai/models')->assertOk()->json('free');

        $this->assertSame(['openrouter/free'], array_column($free, 'id'));
        $this->assertSame('Auto Free Router', $free[0]['name']);
    }

    public function test_an_unreachable_openrouter_is_a_502(): void
    {
        Http::fake(['openrouter.ai/api/v1/models' => Http::response('nope', 503)]);

        $this->getJson('/api/ai/models')->assertStatus(502)->assertJsonStructure(['error']);
    }
}
