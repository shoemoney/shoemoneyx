<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Optimizer\SearchSpace;
use InvalidArgumentException;
use Tests\TestCase;

class SearchSpaceTest extends TestCase
{
    public function test_every_shipped_space_loads_with_the_core_knobs(): void
    {
        foreach (['mr', 'mr-short'] as $name) {
            $dims = SearchSpace::named($name)->dims;
            foreach (['mr.timeframe', 'mr.entry_z', 'mr.qty_pct', 'mr.stop_z'] as $key) {
                $this->assertArrayHasKey($key, $dims, "{$name} lacks {$key}");
                $this->assertNotEmpty($dims[$key]);
            }
        }
    }

    public function test_resolve_routes_a_bare_name_through_named(): void
    {
        $this->assertSame(SearchSpace::named('mr')->dims, SearchSpace::resolve('mr')->dims);
    }

    public function test_resolve_routes_a_json_suffixed_spec_through_from_file(): void
    {
        $path = config_path('spaces/mr.json');

        $viaResolve = SearchSpace::resolve($path);
        $viaFromFile = SearchSpace::fromFile($path);

        $this->assertSame($viaFromFile->dims, $viaResolve->dims);
        $this->assertSame(SearchSpace::named('mr')->dims, $viaResolve->dims);
    }

    public function test_resolve_routes_a_spec_containing_a_slash_through_from_file(): void
    {
        $relative = 'config/spaces/mr.json';

        $this->assertSame(SearchSpace::named('mr')->dims, SearchSpace::resolve($relative)->dims);
    }

    public function test_from_file_accepts_a_relative_path(): void
    {
        $this->assertSame(
            SearchSpace::named('mr-short')->dims,
            SearchSpace::fromFile('config/spaces/mr-short.json', 'mr.')->dims
        );
    }

    public function test_unknown_name_lists_available_spaces(): void
    {
        try {
            SearchSpace::named('does-not-exist');
            $this->fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('does-not-exist', $e->getMessage());
            $this->assertStringContainsString('mr', $e->getMessage());
        }
    }

    public function test_about_key_is_stripped(): void
    {
        $this->assertArrayNotHasKey('_about', SearchSpace::named('mr')->dims);
    }

    public function test_empty_array_throws_naming_the_key(): void
    {
        $path = $this->writeSpace(['mr.timeframe' => []]);

        try {
            SearchSpace::fromFile($path, 'mr.');
            $this->fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('mr.timeframe', $e->getMessage());
        }
    }

    public function test_non_scalar_value_throws_naming_the_key(): void
    {
        $path = $this->writeSpace(['mr.entry_z' => [3, [4, 5]]]);

        try {
            SearchSpace::fromFile($path, 'mr.');
            $this->fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('mr.entry_z', $e->getMessage());
        }
    }

    public function test_wrong_prefixed_key_throws_naming_the_key(): void
    {
        $path = $this->writeSpace(['b2.timeframe' => ['15s', '30s']]);

        try {
            SearchSpace::fromFile($path, 'mr.');
            $this->fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('b2.timeframe', $e->getMessage());
        }
    }

    public function test_a_prefixed_file_passes_with_its_own_prefix_and_fails_with_another(): void
    {
        $path = $this->writeSpace(['custom.timeframe' => ['15s', '30s']]);

        $space = SearchSpace::fromFile($path, 'custom.');
        $this->assertSame(['custom.timeframe' => ['15s', '30s']], $space->dims);

        try {
            SearchSpace::fromFile($path, 'mr.');
            $this->fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('custom.timeframe', $e->getMessage());
        }
    }

    public function test_only_keeps_the_named_keys(): void
    {
        $space = SearchSpace::named('mr')->only(['mr.timeframe', 'mr.qty_pct']);

        $this->assertSame(['mr.timeframe', 'mr.qty_pct'], array_keys($space->dims));
    }

    public function test_with_overrides_and_adds_dims(): void
    {
        $space = SearchSpace::named('mr')->with([
            'mr.timeframe' => ['1m'],
            'mr.new_knob' => [1, 2],
        ]);

        $this->assertSame(['1m'], $space->dims['mr.timeframe']);
        $this->assertSame([1, 2], $space->dims['mr.new_knob']);
        $this->assertSame(SearchSpace::named('mr')->dims['mr.entry_z'], $space->dims['mr.entry_z']);
    }

    /** Write a throwaway space JSON file for a validation-failure test and return its path. */
    private function writeSpace(array $dims): string
    {
        $path = tempnam(sys_get_temp_dir(), 'search-space-').'.json';
        file_put_contents($path, json_encode($dims));
        $this->tempFiles[] = $path;

        return $path;
    }

    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }
}
