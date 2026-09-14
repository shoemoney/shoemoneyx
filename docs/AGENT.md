# Strategy Builder Agent

A tool-calling agent that walks a trader through building a strategy one phase at a
time, backed by `App\Ai\StrategyAgent` and the system prompt in
`App\Ai\Prompts\StrategyBuilderPrompt`. Distinct from the older SMX assist chat
(`StrategyPluginController::assist()` / `App\Desk\Assist\SmxPrompt`), which is
untouched and still the free-form chatbox in the builder UI.

## The seven phases

| # | Phase | Decides | Schema section |
|---|---|---|---|
| 1 | Setup | Market condition that must hold: regime, session hours, volatility | `setup.rules` |
| 2 | Trigger | The exact signal, with concrete numeric thresholds | `trigger.rules`, `trigger.max_candidates` |
| 3 | Entry | Side, confirmation checks, sizing rule | `entry.side`, `entry.confirm`, `entry.sizing` |
| 4 | Management | Adds, trailing stop, partials — or explicitly none | `management.adds`, `management.trailing`, `management.partials` |
| 5 | Exit | Stop, take profit, and time stop — all three decided | `exit.stop`, `exit.take_profit`, `exit.time_stop` |
| 6 | Risk | Max positions, daily loss cap, leverage cap | `risk.max_positions`, `risk.daily_loss_cap_pct`, `risk.leverage_cap` |
| 7 | Review | Restate the full strategy, validate, save, offer a backtest | the whole definition |

The prompt asks exactly one question per turn, refuses to advance a phase until every
field of the current phase is decided, and only ever produces the final JSON through
the `strategy_json` tool call in Phase 7 — never pasted into a chat reply.

## Tools

All tools live in `App\Ai\Tools\*`, registered via `App\Ai\Tools\ToolRegistry`.

| Tool | Purpose | Params | Example |
|---|---|---|---|
| `strategy_json` | Validate and save a strategy definition as a new plugin version | `definition` (required), `bump?`, `changelog?` | Called once in Phase 7 with the full sectioned definition |
| `run_backtest` | Run a backtest against a saved plugin, optionally pinned to a version | `plugin_id?`, `version?`, `days?`, `cash?`, `products?` | After saving, run 30 days on `BTC-USD` |
| `get_backtest` | Look up a previously run backtest by id | `id` (required) | "What were the results of run #42?" |
| `compare_versions` | Structurally diff two saved versions of a plugin | `plugin_id`, `from`, `to` (all required) | Diff `1.0.0` against `1.1.0` |
| `list_versions` | List every saved version of a plugin, newest first | `plugin_id` (required) | "What versions do I have?" |
| `suggest_share` | Surface the one-time "publish to the community archive?" UI prompt | `plugin_id?`, `version?` | Called once, right after a successful `run_backtest` |
| `set_phase` | Advance (or set) the current phase, 1-7 | `phase` (required, 1-7) | Called whenever the model moves to a new phase |

Every tool's `run()` returns a JSON-serializable array and never throws — a missing
plugin, backtest or version comes back as `{"error": "..."}` so a bad lookup degrades
the conversation instead of ending the agent's turn.

## `agent_conversations` schema and routes

Table `agent_conversations` (migration
`database/migrations/2026_09_10_000004_create_agent_conversations_table.php`):

| Column | Type | Notes |
|---|---|---|
| `user_id` | nullable FK → `users`, null on delete | |
| `plugin_id` | nullable FK → `strategy_plugins`, null on delete | the plugin this conversation is building/editing |
| `messages` | json | OpenAI-shape message list, capped at 40 entries per `App\Ai\StrategyAgent` |
| `phase` | tinyint, default 1 | current phase, 1-7 |

Routes (`routes/api.php`, inside the existing `DeskToken` group):

```
POST /api/agent/conversations                        # create a conversation, optional plugin_id
POST /api/agent/conversations/{conversation}/turn     # send a user message, get back {content, tool_events, phase}
GET  /api/agent/conversations/{conversation}          # read the conversation row
```

`StrategyAgent::turn()` caps tool execution at 6 per turn (not 6 rounds — 6 total tool
*calls* across however many rounds it takes) and caps stored history at the newest 40
messages.

## MCP client support

`App\Ai\Mcp\McpClient` is a minimal synchronous JSON-RPC-over-stdio client for a
long-lived MCP server child process. `App\Ai\Mcp\McpToolLoader::extend()` reads
`config('mcp.servers')`, spawns each enabled server, and registers every tool it
reports as a native `App\Ai\Tools\Tool` (named `mcp.{server}.{tool}`) — wired into
`ToolRegistry::withDefaults()`, so every MCP tool a configured server exposes shows up
alongside the seven built-in tools automatically.

Configure servers via the `MCP_SERVERS` env var — a JSON object, decoded into
`config/mcp.php`'s `servers` key:

```json
{
  "tradingview": { "command": "npx", "args": ["-y", "tradingview-mcp"], "env": {} }
}
```

Each entry: `command` (required), `args` (list, optional), `env` (map, optional),
`enabled` (bool, default `true` — set `false` to keep an entry around but skip it). A
server that fails to spawn or answer `initialize`/`tools/list` is reported and skipped
— one bad MCP server never breaks the rest of the agent's tools.

**`tradingview-mcp` is not active by default and is not a hosted market-data API.** As
of writing, the npm package `tradingview-mcp` (v1.0.1) bridges a *running TradingView
Desktop app* over Chrome DevTools Protocol — it needs that desktop app open locally to
answer anything. Verify it still fits your setup before setting `MCP_SERVERS` to enable
it; `config/mcp.php` documents the exact shape as a comment rather than an active
default.

## ChatClient contract and OpenRouter implementation

The agent codes against `App\Ai\Contracts\ChatClient` / `App\Ai\ChatResponse` and does
not assume anything beyond that interface:

```php
interface ChatClient
{
    public function chat(array $messages, array $tools = [], ?string $model = null, array $options = []): ChatResponse;
    public function defaultModel(): string;
}
```

`$messages` and `$tools` follow the OpenAI chat-completions shape. `ChatResponse`
carries `content`, `toolCalls` (`list<array{id, name, arguments}>`), `model`, `usage`,
and `finishReason`.

### OpenRouterChatClient

`App\Ai\OpenRouterChatClient` implements this interface and ships production-ready.
It's bound via `App\Providers\AiServiceProvider::register()`:

```php
$this->app->bind(ChatClient::class, OpenRouterChatClient::class);
```

The implementation:
- Routes all chat completions through the operator's connected OpenRouter account.
- Resolves API keys from user `AiConnection` rows (with audit trail) or from
  `services.openrouter.key` in config/services.php for self-hosted setups.
- Every request is gated and audited by `App\Ai\Gate`: `ensureAllowed()` runs before
  the HTTP call, `record()` logs metadata after (prompts, completions, tool arguments
  never leak into logs).
- Handles OpenRouter 429 rate limits with a single 30-second backoff retry (looping
  against a rate limit digs deeper, so one retry maximum).
- Parses OpenRouter's response into `ChatResponse`, extracting tool calls, usage, and
  finish reason into the standard shape.
