<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Contracts\ChatClient;
use App\Ai\Prompts\StrategyBuilderPrompt;
use App\Ai\Tools\ToolRegistry;
use App\Models\AgentConversation;

final class StrategyAgent
{
    private const MAX_TOOL_CALLS = 6;

    private const MAX_HISTORY = 40;

    public function __construct(
        private readonly ChatClient $chatClient,
        private readonly ToolRegistry $tools,
    ) {}

    /** @return array{content: ?string, tool_events: list<array>, phase: int} */
    public function turn(int $conversationId, string $userMessage): array
    {
        $conversation = AgentConversation::findOrFail($conversationId);
        $messages = $conversation->messages ?? [];
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $context = new AgentContext($conversationId, $conversation->plugin_id, $conversation->phase ?? 1);
        $toolEvents = [];
        $executed = 0;
        $content = null;

        while (true) {
            $payload = array_merge(
                [['role' => 'system', 'content' => StrategyBuilderPrompt::system()]],
                self::capped($messages),
            );
            $response = $this->chatClient->chat($payload, $this->tools->schemas());

            if (! $response->hasToolCalls()) {
                $content = $response->content;
                $messages[] = ['role' => 'assistant', 'content' => $response->content];
                break;
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $response->content,
                'tool_calls' => array_map(fn ($c) => [
                    'id' => $c['id'],
                    'type' => 'function',
                    'function' => ['name' => $c['name'], 'arguments' => json_encode($c['arguments'])],
                ], $response->toolCalls),
            ];

            $capped = false;
            foreach ($response->toolCalls as $call) {
                if ($executed >= self::MAX_TOOL_CALLS) {
                    $capped = true;
                    break;
                }
                $tool = $this->tools->get($call['name']);
                $result = $tool ? $tool->run($call['arguments'], $context) : ['error' => "unknown tool: {$call['name']}"];
                $executed++;
                $toolEvents[] = ['tool' => $call['name'], 'args' => $call['arguments'], 'result' => $result];
                $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => json_encode($result)];
            }
            if ($capped) {
                $content = $response->content;
                break;
            }
        }

        $conversation->update([
            'messages' => self::capped($messages),
            'phase' => $context->phase,
            'plugin_id' => $context->pluginId,
        ]);

        return ['content' => $content, 'tool_events' => $toolEvents, 'phase' => $context->phase];
    }

    /** @return list<array> */
    private static function capped(array $messages): array
    {
        return array_slice($messages, -self::MAX_HISTORY);
    }
}
