<?php

declare(strict_types=1);

namespace App\Ai\LangChain;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;

/** Keeps the database/ChatClient transcript independent of SDK serialization. */
final class MessageCodec
{
    /**
     * Repair transcripts written before complete tool batches were enforced. Missing results
     * are unknown outcomes, never instructions to replay a historical side effect.
     *
     * @return list<array>
     */
    public static function normalizeHistory(array $messages): array
    {
        $normalized = [];
        $pending = [];
        $finishBatch = static function () use (&$normalized, &$pending): void {
            foreach ($pending as $id => $call) {
                $normalized[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) $id,
                    'content' => json_encode(['error' => 'The result of this earlier tool call was not recorded. It has not been re-executed during history recovery.'], JSON_THROW_ON_ERROR),
                ];
            }
            $pending = [];
        };

        foreach ($messages as $message) {
            if ($message['role'] === 'tool') {
                $id = $message['tool_call_id'] ?? '';
                if (isset($pending[$id])) {
                    $normalized[] = $message;
                    unset($pending[$id]);
                }

                // An older individual-message slice may start with an orphan tool result.
                // Duplicate or otherwise unpaired results are invalid for the same reason.
                continue;
            }

            $finishBatch();
            $normalized[] = $message;
            if ($message['role'] === 'assistant') {
                foreach ($message['tool_calls'] ?? [] as $call) {
                    $pending[$call['id']] = $call;
                }
            }
        }
        $finishBatch();

        return $normalized;
    }

    public static function fromWire(array $message): BaseMessage
    {
        $fields = ['content' => $message['content'] ?? '', 'response_metadata' => ['app_null_content' => ($message['content'] ?? null) === null]];

        return match ($message['role']) {
            'user' => new HumanMessage($fields),
            'system' => new SystemMessage($fields),
            'tool' => new ToolMessage($fields + ['tool_call_id' => $message['tool_call_id']]),
            'assistant' => new AIMessage($fields + ['tool_calls' => array_map(static fn ($call) => [
                'id' => $call['id'],
                'name' => $call['function']['name'],
                'args' => $call['function']['name'] === 'hub_register' ? [] : json_decode($call['function']['arguments'], true, flags: JSON_THROW_ON_ERROR),
                'type' => 'tool_call',
            ], $message['tool_calls'] ?? [])]),
            default => throw new \InvalidArgumentException('Unsupported agent message role.'),
        };
    }

    public static function toWire(BaseMessage $message): array
    {
        $wire = [
            'role' => match ($message->type) {
                'human' => 'user', 'ai' => 'assistant', 'system' => 'system', 'tool' => 'tool',
                default => throw new \InvalidArgumentException('Unsupported graph message role.'),
            },
            'content' => ($message->response_metadata['app_null_content'] ?? false) ? null : $message->text(),
        ];
        if ($message instanceof AIMessage && $message->toolCalls !== []) {
            $wire['tool_calls'] = array_map(static fn ($call) => [
                'id' => $call['id'], 'type' => 'function',
                'function' => ['name' => $call['name'], 'arguments' => json_encode($call['name'] === 'hub_register' ? (object) [] : $call['args'], JSON_THROW_ON_ERROR)],
            ], $message->toolCalls);
        }
        if ($message instanceof ToolMessage) {
            $wire['tool_call_id'] = $message->toolCallId;
        }

        return $wire;
    }
}
