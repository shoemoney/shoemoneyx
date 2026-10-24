<?php

declare(strict_types=1);

namespace App\Ai\LangChain;

use App\Ai\AgentContext;
use App\Ai\Contracts\ChatClient;
use App\Ai\Prompts\StrategyBuilderPrompt;
use App\Ai\StrategyAgent;
use App\Ai\Tools\ToolRegistry;
use LangChain\Messages\AIMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tools\DynamicStructuredTool;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware;

/** One bounded graph invocation, with a journal that survives later provider failures. */
final class StrategyGraph
{
    public array $messages;

    public array $toolEvents = [];

    public ?string $content = null;

    public ?string $error = null;

    private int $executed = 0;

    private bool $blankRetried = false;

    private bool $concluding = false;

    public function __construct(
        private readonly ChatClient $client,
        private readonly ToolRegistry $registry,
        private readonly AgentContext $context,
        array $messages,
        private readonly \Closure $capHistory,
    ) {
        // Repair earlier incomplete batches without replaying tools, and scrub legacy hub
        // arguments before graph state, model requests or database persistence sees them.
        $this->messages = array_map(static fn ($message) => MessageCodec::toWire(MessageCodec::fromWire($message)), MessageCodec::normalizeHistory($messages));
    }

    public function run(): void
    {
        try {
            $tools = array_map(fn ($tool) => new DynamicStructuredTool([
                'name' => $tool->name(), 'description' => $tool->description(),
                // The SDK validator uses arrays internally for JSON Schema objects.
                'schema' => json_decode(json_encode($tool->parameters(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
            ], fn (array $args) => $tool->run($args, $this->context)), $this->registry->all());

            $graph = Agent::create([
                'model' => new ChatClientModel($this->client, $this->messages),
                'tools' => $tools,
                // v1 executes each batch sequentially, retaining application tool ordering.
                'version' => 'v1',
                'systemPrompt' => StrategyBuilderPrompt::system(),
                'middleware' => [Middleware::create([
                    'name' => 'DeskTurnPolicy',
                    'wrapModelCall' => $this->modelCall(...),
                    'wrapToolCall' => $this->toolCall(...),
                    'afterModel' => ['hook' => $this->afterModel(...), 'canJumpTo' => ['end']],
                ])],
            ]);
            $graph->invoke(['messages' => array_map(MessageCodec::fromWire(...), ($this->capHistory)($this->messages))], ['recursionLimit' => 40]);
        } catch (\Throwable $error) {
            // Middleware wraps causes. Preserve the actual provider reason for the API.
            while ($error->getPrevious() !== null) {
                $error = $error->getPrevious();
            }
            $this->error = $error->getMessage();
        }
    }

    private function modelCall(array $request, callable $handler): AIMessage
    {
        $request['messages'] = array_map(MessageCodec::fromWire(...), ($this->capHistory)($this->messages));
        $this->concluding = $this->executed >= 6;
        if ($this->concluding) {
            $request['tools'] = [];
        }
        $response = $handler($request);
        if ($response->toolCalls === [] && trim($response->text()) === '' && $this->executed === 0 && ! $this->blankRetried) {
            $this->blankRetried = true;
            $response = $handler($request);
        }
        $this->messages[] = MessageCodec::toWire($response);
        if ($response->toolCalls === []) {
            $this->content = ($response->response_metadata['app_null_content'] ?? false) ? null : $response->text();
        }

        return $response;
    }

    private function toolCall(array $request, callable $handler): ToolMessage
    {
        $call = $request['toolCall'];
        if ($this->executed >= 6) {
            return $this->recordTool($call, ['error' => 'Tool call skipped: this turn has reached its six-call limit.']);
        }
        try {
            $message = $handler($request);
            $result = json_decode($message->text(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            $result = ['error' => 'Tool execution failed: '.$error->getMessage()];
        }
        $this->executed++;
        $this->toolEvents[] = ['tool' => $call['name'], 'args' => $call['args'], 'result' => $result];

        return $this->recordTool($call, $result);
    }

    private function recordTool(array $call, array $result): ToolMessage
    {
        $message = new ToolMessage([
            'content' => StrategyAgent::toolContent($call['name'], $result),
            'tool_call_id' => $call['id'], 'name' => $call['name'],
        ]);
        $this->messages[] = MessageCodec::toWire($message);

        return $message;
    }

    private function afterModel(array $state): ?array
    {
        $last = $state['messages'][array_key_last($state['messages'])];
        if (! $this->concluding || ! $last instanceof AIMessage || $last->toolCalls === []) {
            return null;
        }
        // A provider can ignore the disabled tools. Complete its protocol without
        // executing more side effects or sending an unbounded eighth model request.
        $extra = [];
        foreach ($last->toolCalls as $call) {
            $extra[] = $this->recordTool($call, ['error' => 'Tool call skipped: this turn has reached its six-call limit.']);
        }
        $this->content = $last->text() ?: 'The tool limit for this turn has been reached. Send another message to continue.';
        $message = new AIMessage($this->content);
        $this->messages[] = MessageCodec::toWire($message);
        $extra[] = $message;

        return ['messages' => $extra, 'jumpTo' => 'end'];
    }
}
