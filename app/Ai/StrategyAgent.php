<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Contracts\ChatClient;
use App\Ai\LangChain\StrategyGraph;
use App\Ai\Tools\ToolRegistry;
use App\Models\AgentConversation;

final class StrategyAgent
{
    private const MAX_HISTORY = 40;

    /** Tools whose results carry community-written text (names, changelogs, manifests) or third-party MCP output. */
    private const UNTRUSTED_TOOLS = ['list_versions', 'compare_versions', 'get_backtest', 'sync_strategies'];

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
        $graph = new StrategyGraph($this->chatClient, $this->tools, $context, $messages, self::capped(...));
        $graph->run();

        $conversation->update([
            'messages' => self::capped($graph->messages),
            'phase' => $context->phase,
            'plugin_id' => $context->pluginId,
        ]);

        return ['content' => $graph->content, 'tool_events' => $graph->toolEvents, 'phase' => $context->phase, 'error' => $graph->error];
    }

    /** Community text is fenced as data so the model does not read it as instructions. */
    public static function toolContent(string $toolName, array $result): string
    {
        $json = json_encode($result);

        if (! in_array($toolName, self::UNTRUSTED_TOOLS, true) && ! str_starts_with($toolName, 'mcp.')) {
            return $json;
        }

        // Strip the closing marker so the payload cannot break out of the fence.
        $json = str_replace('</untrusted_data>', '', (string) $json);

        return "<untrusted_data>\n{$json}\n</untrusted_data>";
    }

    /** @return list<array> */
    private static function capped(array $messages): array
    {
        // Keep whole turns. Slicing individual messages can leave tool results without
        // the assistant tool_calls they answer, which providers reject on the next turn.
        $turns = [];
        foreach ($messages as $message) {
            if ($message['role'] === 'user' || $turns === []) {
                $turns[] = [];
            }
            $turns[array_key_last($turns)][] = $message;
        }

        $kept = [];
        foreach (array_reverse($turns) as $turn) {
            if ($kept !== [] && count($turn) + count($kept) > self::MAX_HISTORY) {
                break;
            }
            // A single large batch must remain intact, even when it exceeds the normal
            // history target. Dropping part of that turn would invalidate the protocol.
            $kept = array_merge($turn, $kept);
        }

        return $kept;
    }
}
