<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\Mcp\McpToolLoader;

final class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    /** @param iterable<Tool> $tools */
    public function __construct(iterable $tools = [])
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    public function register(Tool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /** @return list<Tool> */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /** OpenAI function-tool shape for ChatClient::chat()'s $tools argument. */
    public function schemas(): array
    {
        return array_map(fn (Tool $t) => [
            'type' => 'function',
            'function' => ['name' => $t->name(), 'description' => $t->description(), 'parameters' => $t->parameters()],
        ], $this->all());
    }

    public static function withDefaults(): self
    {
        $registry = new self([
            new StrategyJsonTool,
            new RunBacktestTool,
            new GetBacktestTool,
            new CompareVersionsTool,
            new ListVersionsTool,
            new SuggestShareTool,
            new SetPhaseTool,
            new HubRegisterTool,
            new HubStatusTool,
            new PublishStrategyTool,
            new SyncStrategiesTool,
            new StartArenaSeatTool,
            new ArenaScoreboardTool,
        ]);
        McpToolLoader::extend($registry);

        return $registry;
    }
}
