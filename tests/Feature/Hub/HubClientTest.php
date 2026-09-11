<?php

declare(strict_types=1);

namespace Tests\Feature\Hub;

use App\Hub\HubClient;
use App\Hub\HubConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * One case per HubClient public method that makes an HTTP call: exact verb, exact
 * URL (including query string), exact decoded body, and the method's return value
 * equals the faked response body literally.
 *
 * NOTE: orders(), paperOrders(), paperAccount(), paperTape(), paperReset() are not
 * covered here — they were removed. Paper trading stays local via an arena seat; the
 * desk only reports fills/snapshots to the hub now (pushFills(), pushSnapshot(),
 * covered in ContestReporterTest.php since they're exercised through ContestReporter,
 * not called directly elsewhere). See docs/HUB_API.md's Contests section and its new
 * "v2: hosted exchange (not built yet)" section, which documents the removed routes
 * as reserved-but-unimplemented.
 */
class HubClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.url' => 'https://hub.test']);
    }

    public function test_every_covered_hub_client_method_sends_the_documented_request_and_returns_the_response(): void
    {
        $client = app(HubClient::class);

        $cases = [
            'register' => [
                'call' => fn () => $client->register('jeremy@shoemoney.com', 'shoemoney', 'supersecret1', 'laptop'),
                'method' => 'POST',
                'url' => 'https://hub.test/api/v1/register',
                'body' => ['email' => 'jeremy@shoemoney.com', 'handle' => 'shoemoney', 'password' => 'supersecret1', 'desk_name' => 'laptop'],
                'fake' => ['user' => ['id' => 1, 'handle' => 'shoemoney'], 'token' => 'tok-abc'],
            ],
            'login' => [
                'call' => fn () => $client->login('jeremy@shoemoney.com', 'supersecret1', 'laptop'),
                'method' => 'POST',
                'url' => 'https://hub.test/api/v1/login',
                'body' => ['email' => 'jeremy@shoemoney.com', 'password' => 'supersecret1', 'desk_name' => 'laptop'],
                'fake' => ['user' => ['id' => 1, 'handle' => 'shoemoney'], 'token' => 'tok-abc'],
            ],
            'link' => [
                'call' => fn () => $client->link('ONE-TIME-CODE', 'laptop'),
                'method' => 'POST',
                'url' => 'https://hub.test/api/v1/link',
                'body' => ['code' => 'ONE-TIME-CODE', 'desk_name' => 'laptop'],
                'fake' => ['user' => ['id' => 1, 'handle' => 'shoemoney'], 'token' => 'tok-abc'],
            ],
            'me' => [
                'call' => fn () => $client->me(),
                'method' => 'GET',
                'url' => 'https://hub.test/api/v1/me',
                'body' => [],
                'fake' => ['user' => ['id' => 1, 'handle' => 'shoemoney', 'bio' => null, 'avatar_url' => null, 'followers' => 0, 'following' => 0, 'published' => 0], 'desk' => ['id' => 1, 'name' => 'laptop', 'created_at' => '2026-01-01T00:00:00Z']],
            ],
            'updateMe' => [
                'call' => fn () => $client->updateMe(['handle' => 'newhandle', 'bio' => 'hi']),
                'method' => 'PATCH',
                'url' => 'https://hub.test/api/v1/me',
                'body' => ['handle' => 'newhandle', 'bio' => 'hi'],
                'fake' => ['user' => ['id' => 1, 'handle' => 'newhandle', 'bio' => 'hi']],
            ],
            'deleteDesk' => [
                'call' => fn () => $client->deleteDesk(5),
                'method' => 'DELETE',
                'url' => 'https://hub.test/api/v1/me/desks/5',
                'body' => [],
                'fake' => [],
                'void' => true,
            ],
            'publish' => [
                'call' => fn () => $client->publish(['slug' => 'mr-h1', 'name' => 'MR H1', 'description' => 'd', 'tags' => ['mr'], 'version' => '1.0.0', 'definition' => ['key' => 'mr-h1']]),
                'method' => 'POST',
                'url' => 'https://hub.test/api/v1/strategies',
                'body' => ['slug' => 'mr-h1', 'name' => 'MR H1', 'description' => 'd', 'tags' => ['mr'], 'version' => '1.0.0', 'definition' => ['key' => 'mr-h1']],
                'fake' => ['strategy' => ['id' => 1, 'slug' => 'mr-h1', 'url' => 'https://hub.test/s/mr-h1'], 'version' => ['id' => 1, 'version' => '1.0.0']],
            ],
            'publishVersion' => [
                'call' => fn () => $client->publishVersion('mr-h1', ['version' => '1.1.0', 'changelog' => 'tweak', 'definition' => ['key' => 'mr-h1']]),
                'method' => 'POST',
                'url' => 'https://hub.test/api/v1/strategies/mr-h1/versions',
                'body' => ['version' => '1.1.0', 'changelog' => 'tweak', 'definition' => ['key' => 'mr-h1']],
                'fake' => ['version' => ['id' => 2, 'version' => '1.1.0']],
            ],
            'strategy' => [
                'call' => fn () => $client->strategy('mr-h1'),
                'method' => 'GET',
                'url' => 'https://hub.test/api/v1/strategies/mr-h1',
                'body' => [],
                'fake' => ['strategy' => ['id' => 1, 'slug' => 'mr-h1'], 'author' => ['handle' => 'shoemoney'], 'current_version' => '1.1.0', 'versions' => [], 'stats' => ['stars' => 0, 'imports' => 0, 'comments' => 0]],
            ],
            'version' => [
                'call' => fn () => $client->version('mr-h1', '1.0.0'),
                'method' => 'GET',
                'url' => 'https://hub.test/api/v1/strategies/mr-h1/versions/1.0.0',
                'body' => [],
                'fake' => ['version' => '1.0.0', 'definition' => ['key' => 'mr-h1']],
            ],
            'search' => [
                'call' => fn () => $client->search([
                    'q' => 'ema', 'exchange' => 'coinbase', 'timeframe' => '1h', 'asset' => 'BTC-USD',
                    'min_win_rate' => 55, 'max_drawdown' => 20, 'author' => 'jer', 'tag' => 'trend',
                    'sort' => 'stars', 'page' => 2,
                ]),
                'method' => 'GET',
                'url' => 'https://hub.test/api/v1/strategies/search?q=ema&exchange=coinbase&timeframe=1h&asset=BTC-USD&min_win_rate=55&max_drawdown=20&author=jer&tag=trend&sort=stars&page=2',
                'body' => [
                    'q' => 'ema', 'exchange' => 'coinbase', 'timeframe' => '1h', 'asset' => 'BTC-USD',
                    'min_win_rate' => 55, 'max_drawdown' => 20, 'author' => 'jer', 'tag' => 'trend',
                    'sort' => 'stars', 'page' => 2,
                ],
                'fake' => ['results' => [], 'total' => 0, 'page' => 2],
            ],
            'search_omits_null_filters' => [
                'call' => fn () => $client->search(['q' => 'ema', 'exchange' => null, 'sort' => null, 'page' => 1]),
                'method' => 'GET',
                'url' => 'https://hub.test/api/v1/strategies/search?q=ema&page=1',
                'body' => ['q' => 'ema', 'page' => 1],
                'fake' => ['results' => [], 'total' => 0, 'page' => 1],
            ],
            'typeahead' => [
                'call' => fn () => $client->typeahead('me'),
                'method' => 'GET',
                'url' => 'https://hub.test/api/v1/strategies/typeahead?q=me',
                'body' => ['q' => 'me'],
                'fake' => ['results' => [['slug' => 'mr-h1', 'name' => 'MR H1', 'author' => 'shoemoney']]],
            ],
            'star' => [
                'call' => fn () => $client->star('mr-h1'),
                'method' => 'POST',
                'url' => 'https://hub.test/api/v1/strategies/mr-h1/star',
                'body' => [],
                'fake' => ['stars' => 4],
            ],
            'unstar' => [
                'call' => fn () => $client->unstar('mr-h1'),
                'method' => 'DELETE',
                'url' => 'https://hub.test/api/v1/strategies/mr-h1/star',
                'body' => [],
                'fake' => ['stars' => 3],
            ],
            'import' => [
                'call' => fn () => $client->import('mr-h1'),
                'method' => 'POST',
                'url' => 'https://hub.test/api/v1/strategies/mr-h1/import',
                'body' => [],
                'fake' => ['ok' => true],
            ],
            'deleteStrategy' => [
                'call' => fn () => $client->deleteStrategy('mr-h1'),
                'method' => 'DELETE',
                'url' => 'https://hub.test/api/v1/strategies/mr-h1',
                'body' => [],
                'fake' => [],
                'void' => true,
            ],
            'user' => [
                'call' => fn () => $client->user('shoemoney'),
                'method' => 'GET',
                'url' => 'https://hub.test/api/v1/users/shoemoney',
                'body' => [],
                'fake' => ['user' => ['handle' => 'shoemoney'], 'strategies' => [], 'contests' => []],
            ],
            'follow' => [
                'call' => fn () => $client->follow('shoemoney'),
                'method' => 'POST',
                'url' => 'https://hub.test/api/v1/users/shoemoney/follow',
                'body' => [],
                'fake' => ['following' => true],
            ],
            'unfollow' => [
                'call' => fn () => $client->unfollow('shoemoney'),
                'method' => 'DELETE',
                'url' => 'https://hub.test/api/v1/users/shoemoney/follow',
                'body' => [],
                'fake' => ['following' => false],
            ],
            'feed' => [
                'call' => fn () => $client->feed(3),
                'method' => 'GET',
                'url' => 'https://hub.test/api/v1/feed?page=3',
                'body' => ['page' => 3],
                'fake' => ['items' => []],
            ],
            'comments' => [
                'call' => fn () => $client->comments('mr-h1', 2),
                'method' => 'GET',
                'url' => 'https://hub.test/api/v1/strategies/mr-h1/comments?page=2',
                'body' => ['page' => 2],
                'fake' => ['comments' => []],
            ],
            'postComment' => [
                'call' => fn () => $client->postComment('mr-h1', ['body' => 'nice work', 'version' => '1.0.0']),
                'method' => 'POST',
                'url' => 'https://hub.test/api/v1/strategies/mr-h1/comments',
                'body' => ['body' => 'nice work', 'version' => '1.0.0'],
                'fake' => ['comment' => ['id' => 9, 'body' => 'nice work']],
            ],
            'deleteComment' => [
                'call' => fn () => $client->deleteComment(9),
                'method' => 'DELETE',
                'url' => 'https://hub.test/api/v1/comments/9',
                'body' => [],
                'fake' => [],
                'void' => true,
            ],
            'contests_with_state' => [
                'call' => fn () => $client->contests('live'),
                'method' => 'GET',
                'url' => 'https://hub.test/api/v1/contests?state=live',
                'body' => ['state' => 'live'],
                'fake' => ['contests' => []],
            ],
            'contests_without_state' => [
                'call' => fn () => $client->contests(),
                'method' => 'GET',
                'url' => 'https://hub.test/api/v1/contests',
                'body' => [],
                'fake' => ['contests' => []],
            ],
            'contest' => [
                'call' => fn () => $client->contest('spring-open'),
                'method' => 'GET',
                'url' => 'https://hub.test/api/v1/contests/spring-open',
                'body' => [],
                'fake' => ['contest' => ['id' => 1, 'slug' => 'spring-open'], 'leaderboard' => []],
            ],
            'enter' => [
                'call' => fn () => $client->enter('spring-open', 'mr-h1', '1.0.0'),
                'method' => 'POST',
                'url' => 'https://hub.test/api/v1/contests/spring-open/enter',
                'body' => ['strategy_slug' => 'mr-h1', 'version' => '1.0.0'],
                'fake' => ['entry' => ['id' => 1, 'frozen_version_id' => 1]],
            ],
            'withdraw' => [
                'call' => fn () => $client->withdraw('spring-open'),
                'method' => 'DELETE',
                'url' => 'https://hub.test/api/v1/contests/spring-open/enter',
                'body' => [],
                'fake' => [],
                'void' => true,
            ],
            'pushFills' => [
                'call' => fn () => $client->pushFills('spring-open', [
                    ['client_id' => 'seat1-fill1', 'product_id' => 'BTC-USD', 'side' => 'buy', 'size' => 0.5, 'price' => 20000, 'fee_usd' => 6, 'at' => '2026-01-01T00:00:00Z'],
                ]),
                'method' => 'POST',
                'url' => 'https://hub.test/api/v1/contests/spring-open/fills',
                'body' => ['fills' => [
                    ['client_id' => 'seat1-fill1', 'product_id' => 'BTC-USD', 'side' => 'buy', 'size' => 0.5, 'price' => 20000, 'fee_usd' => 6, 'at' => '2026-01-01T00:00:00Z'],
                ]],
                'fake' => ['accepted' => 1, 'duplicates' => 0],
            ],
            'pushSnapshot' => [
                'call' => fn () => $client->pushSnapshot('spring-open', ['equity' => 1050.25, 'cash' => 900.5, 'open_positions' => [], 'at' => '2026-01-01T00:01:00Z']),
                'method' => 'POST',
                'url' => 'https://hub.test/api/v1/contests/spring-open/snapshots',
                'body' => ['equity' => 1050.25, 'cash' => 900.5, 'open_positions' => [], 'at' => '2026-01-01T00:01:00Z'],
                'fake' => ['ok' => true],
            ],
            'account' => [
                'call' => fn () => $client->account('spring-open'),
                'method' => 'GET',
                'url' => 'https://hub.test/api/v1/contests/spring-open/account',
                'body' => [],
                'fake' => ['snapshot' => ['equity' => 1000.5, 'cash' => 900.5, 'open_positions' => [], 'at' => '2026-01-01T00:01:00Z'], 'fills' => []],
            ],
            'tape' => [
                'call' => fn () => $client->tape('spring-open', 'BTC-USD', ['timeframe' => '1h', 'from' => '2026-01-01', 'to' => '2026-01-02']),
                'method' => 'GET',
                'url' => 'https://hub.test/api/v1/contests/spring-open/tape/BTC-USD?timeframe=1h&from=2026-01-01&to=2026-01-02',
                'body' => ['timeframe' => '1h', 'from' => '2026-01-01', 'to' => '2026-01-02'],
                'fake' => ['candles' => []],
            ],
        ];

        foreach ($cases as $label => $case) {
            // Http::fake() *merges* stub callbacks across calls rather than replacing them, and
            // resolution takes the first matching stub — so a plain `Http::fake(['hub.test/*' =>
            // ...])` repeated in a loop would always resolve to the FIRST iteration's response.
            // Guard each stub so it only answers its own exact method+URL and falls through (null)
            // for every other request, which is how later iterations' stubs actually get reached.
            Http::fake([
                'hub.test/*' => function (HttpRequest $request) use ($case) {
                    if ($request->method() !== $case['method'] || $request->url() !== $case['url']) {
                        return null;
                    }

                    return Http::response($case['fake']);
                },
            ]);

            $result = ($case['call'])();

            Http::assertSentCount(1);
            /** @var HttpRequest $sent */
            $sent = Http::recorded()->first()[0];

            $this->assertSame($case['method'], $sent->method(), "HTTP method mismatch for {$label}");
            $this->assertSame($case['url'], $sent->url(), "URL mismatch for {$label}");
            $this->assertSame($case['body'], $sent->data(), "request body mismatch for {$label}");

            if (! ($case['void'] ?? false)) {
                $this->assertSame($case['fake'], $result, "return value mismatch for {$label}");
            }
        }
    }

    public function test_connected_reflects_whether_an_active_hub_connection_exists(): void
    {
        $client = app(HubClient::class);

        $this->assertFalse($client->connected());

        HubConnection::create([
            'user_handle' => 'shoemoney',
            'desk_id' => null,
            'token' => 'tok-abc',
            'connected_at' => now(),
            'revoked_at' => null,
        ]);

        $this->assertTrue($client->connected());
    }
}
