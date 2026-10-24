<?php

declare(strict_types=1);

namespace App\Ai\LangChain;

use App\Ai\Contracts\ChatClient;
use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\AIMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tools\StructuredTool;
use LangChain\Tracers\CallbackManagerForLLMRun;

/** Graph model adapter; all provider access stays behind the application's audited ChatClient. */
final class ChatClientModel extends BaseChatModel
{
    private array $toolSchemas = [];

    private array $reservedToolCallIds = [];

    public function __construct(private readonly ChatClient $client, array $history = [])
    {
        parent::__construct();
        // The graph retains earlier messages even when they fall outside the model's
        // current history window. Reserve their IDs so ToolNode cannot mistake a new
        // provider call for an already answered historical call.
        foreach ($history as $message) {
            foreach ($message['tool_calls'] ?? [] as $call) {
                $this->reservedToolCallIds[$call['id']] = true;
            }
            if ($message['role'] === 'tool') {
                $this->reservedToolCallIds[$message['tool_call_id']] = true;
            }
        }
    }

    public function llmType(): string
    {
        return 'shoemoneyx-chat-client';
    }

    public function bindTools(array $tools, array $kwargs = []): static
    {
        $bound = clone $this;
        $bound->toolSchemas = array_map(static fn ($tool) => $tool instanceof StructuredTool ? [
            'type' => 'function',
            'function' => ['name' => $tool->name, 'description' => $tool->description, 'parameters' => self::wireSchema($tool->schema->toJsonSchema())],
        ] : $tool, $tools);

        return $bound;
    }

    private static function wireSchema(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = $key === 'properties' && $value === [] ? (object) [] : self::wireSchema($value);
            }
        }

        return $schema;
    }

    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        $response = $this->client->chat(array_map(MessageCodec::toWire(...), $messages), $this->toolSchemas);
        $usedIds = $this->reservedToolCallIds;
        foreach ($messages as $previous) {
            if ($previous instanceof AIMessage) {
                foreach ($previous->toolCalls as $call) {
                    $usedIds[$call['id']] = true;
                }
            } elseif ($previous instanceof ToolMessage) {
                $usedIds[$previous->toolCallId] = true;
            }
        }
        $toolCalls = [];
        foreach ($response->toolCalls as $call) {
            $id = (string) $call['id'];
            if ($id === '' || isset($usedIds[$id])) {
                do {
                    $id = 'smx_'.bin2hex(random_bytes(12));
                } while (isset($usedIds[$id]));
            }
            $usedIds[$id] = true;
            $toolCalls[] = [
                'id' => $id, 'name' => $call['name'],
                // A provider may still emit this tool's obsolete credential arguments.
                // Remove them before graph state, traces, events or database persistence.
                'args' => $call['name'] === 'hub_register' ? [] : $call['arguments'],
                'type' => 'tool_call',
            ];
        }
        $message = new AIMessage([
            'content' => $response->content ?? '',
            'tool_calls' => $toolCalls,
            'response_metadata' => ['model' => $response->model, 'app_null_content' => $response->content === null],
        ]);

        return new ChatResult([new ChatGeneration($message, $response->content ?? '')], $response->usage);
    }
}
