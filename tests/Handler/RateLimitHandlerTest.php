<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Handler;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use Kyzegs\GuzzleRateLimitMiddleware\Config\GlobalLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\Config\InvalidRequestLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Limit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\LimitAlgorithm;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\BucketResolverInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockFactoryInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RateLimitParserInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RetryStrategyInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\StoreInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\InvalidRequestLimitExceededException;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\RateLimitDelayExceededException;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\RateLimitExceededException;
use Kyzegs\GuzzleRateLimitMiddleware\Handler\RateLimitHandler;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\DefaultBucketResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\DiscordBucketResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Retry\ExponentialBackoffRetryStrategy;
use Kyzegs\GuzzleRateLimitMiddleware\Retry\GitHubRetryStrategy;
use Kyzegs\GuzzleRateLimitMiddleware\Retry\StatusCodeRetryStrategy;
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Support\NullLockFactory;
use Kyzegs\GuzzleRateLimitMiddleware\Support\DiscordRetryAfterResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Testing\FakeClock;
use Kyzegs\GuzzleRateLimitMiddleware\Testing\RecordingSleeper;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\GuardedStore;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\SpyLockFactory;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\SpyStore;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\StubRateLimitParser;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\TrackingLockFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

final class RateLimitHandlerTest extends TestCase
{
    private FakeClock $clock;

    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->clock = new FakeClock(1_000.0);
        $this->sleeper = new RecordingSleeper($this->clock);
    }

    public function test_does_not_delay_when_no_state_exists(): void
    {
        $handler = $this->handler($this->store());

        $response = $handler->handle(
            $this->responder(new Response(200, ['X-RateLimit-Remaining' => '5'])),
            new Request('GET', 'https://api.test/users'),
            [],
        )->wait();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $this->sleeper->count());
    }

    public function test_sleeps_until_reset_when_bucket_exhausted(): void
    {
        $store = $this->store();
        $request = new Request('GET', 'https://api.test/users');
        $exhausted = new Response(200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '10']);

        // First call records the exhausted state.
        $this->handler($store)->handle($this->responder($exhausted), $request, [])->wait();
        $this->assertSame(0, $this->sleeper->count());

        // Second call (fresh handler, same store) must wait for the reset window.
        $this->handler($store)->handle($this->responder($exhausted), $request, [])->wait();

        $this->assertSame(1, $this->sleeper->count());
        // 10s until reset + 1s default safety buffer.
        $this->assertEqualsWithDelta(11.0, $this->sleeper->sleeps[0], 0.001);
    }

    public function test_state_persists_across_independent_handler_instances(): void
    {
        $store = $this->store();
        $request = new Request('GET', 'https://api.test/widgets');
        $exhausted = new Response(200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '30']);

        $this->handler($store)->handle($this->responder($exhausted), $request, [])->wait();

        // A brand-new handler, simulating a separate process, sharing only the store.
        $secondSleeper = new RecordingSleeper();
        $secondHandler = new RateLimitHandler(
            new Options(),
            new Headers(),
            $store,
            new NullLogger(),
            new DefaultBucketResolver(),
            new NullLockFactory(),
            new FakeClock(1_000.0),
            $secondSleeper,
        );

        $secondHandler->handle($this->responder($exhausted), $request, [])->wait();

        $this->assertSame(1, $secondSleeper->count(), 'State must survive outside the original operation.');
        $this->assertEqualsWithDelta(31.0, $secondSleeper->sleeps[0], 0.001);
    }

    public function test_retries_429_then_succeeds(): void
    {
        $handler = $this->handler($this->store());

        $response = $handler->handle(
            $this->queue(
                new Response(429, ['Retry-After' => '4']),
                new Response(200, ['X-RateLimit-Remaining' => '9']),
            ),
            new Request('GET', 'https://api.test/users'),
            [],
        )->wait();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $this->sleeper->count());
        $this->assertEqualsWithDelta(5.0, $this->sleeper->sleeps[0], 0.001); // 4 + 1 buffer
    }

    public function test_retry_after_http_date_is_honoured(): void
    {
        $handler = $this->handler($this->store());

        $response = $handler->handle(
            $this->queue(
                new Response(429, ['Retry-After' => gmdate('D, d M Y H:i:s \\G\\M\\T', 1_030)]),
                new Response(200, ['X-RateLimit-Remaining' => '9']),
            ),
            new Request('GET', 'https://api.test/users'),
            [],
        )->wait();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertEqualsWithDelta(31.0, $this->sleeper->sleeps[0], 0.001); // 30 + 1 buffer
    }

    public function test_discord_retry_after_falls_back_to_json_without_consuming_body(): void
    {
        $handler = new RateLimitHandler(
            new Options(maxRetries: 1, safetyBufferSeconds: 0),
            Headers::discord(),
            $this->store(),
            new NullLogger(),
            new DiscordBucketResolver(),
            new NullLockFactory(),
            $this->clock,
            $this->sleeper,
            retryAfterResolver: new DiscordRetryAfterResolver(),
        );

        $response = $handler->handle(
            $this->queue(
                new Response(429, ['Content-Type' => 'application/json'], '{"retry_after":2.5,"global":false}'),
                new Response(200, [], '{"ok":true}'),
            ),
            new Request('GET', 'https://discord.com/api/v10/users/@me'),
            [],
        )->wait();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertEqualsWithDelta(2.5, $this->sleeper->sleeps[0], 0.001);
    }

    public function test_global_budget_is_shared_across_routes_for_same_identity(): void
    {
        $handler = $this->handler($this->store(), new Options(
            safetyBufferSeconds: 0,
            globalLimit: new GlobalLimit(maxRequests: 2, windowSeconds: 1),
        ));
        $respond = $this->responder(new Response(200, ['X-RateLimit-Remaining' => '10']));

        $handler->handle($respond, new Request('GET', 'https://api.test/one', ['Authorization' => 'Bot secret']), [])->wait();
        $handler->handle($respond, new Request('GET', 'https://api.test/two', ['Authorization' => 'Bot secret']), [])->wait();
        $handler->handle($respond, new Request('GET', 'https://api.test/three', ['Authorization' => 'Bot secret']), [])->wait();

        $this->assertSame(1, $this->sleeper->count());
        $this->assertEqualsWithDelta(1.0, $this->sleeper->sleeps[0], 0.001);
    }

    public function test_global_budget_isolated_by_authorization_identity(): void
    {
        $handler = $this->handler($this->store(), new Options(
            safetyBufferSeconds: 0,
            globalLimit: new GlobalLimit(maxRequests: 1, windowSeconds: 1),
        ));
        $respond = $this->responder(new Response(200, ['X-RateLimit-Remaining' => '10']));

        $handler->handle($respond, new Request('GET', 'https://api.test/users', ['Authorization' => 'Bot first']), [])->wait();
        $handler->handle($respond, new Request('GET', 'https://api.test/users', ['Authorization' => 'Bearer second']), [])->wait();

        $this->assertSame(0, $this->sleeper->count());
    }

    public function test_json_global_429_blocks_other_routes(): void
    {
        $store = $this->store();
        $options = new Options(maxRetries: 0, safetyBufferSeconds: 0, globalLimit: new GlobalLimit());
        $handler = new RateLimitHandler(
            $options,
            Headers::discord(),
            $store,
            new NullLogger(),
            new DiscordBucketResolver(),
            new NullLockFactory(),
            $this->clock,
            $this->sleeper,
            retryAfterResolver: new DiscordRetryAfterResolver(),
        );

        try {
            $handler->handle(
                $this->responder(new Response(429, ['Content-Type' => 'application/json'], '{"retry_after":2,"global":true}')),
                new Request('GET', 'https://discord.com/api/v10/channels/1', ['Authorization' => 'Bot secret']),
                [],
            )->wait();
        } catch (RateLimitExceededException) {
        }

        $handler->handle(
            $this->responder(new Response(200, ['X-RateLimit-Remaining' => '1'])),
            new Request('GET', 'https://discord.com/api/v10/guilds/2', ['Authorization' => 'Bot secret']),
            [],
        )->wait();

        $this->assertSame(1, $this->sleeper->count());
        $this->assertEqualsWithDelta(2.0, $this->sleeper->sleeps[0], 0.001);
    }

    public function test_global_field_in_a_successful_body_does_not_block(): void
    {
        $handler = $this->handler(
            $this->store(),
            new Options(safetyBufferSeconds: 0, globalLimit: new GlobalLimit()),
            Headers::discord(),
            new DiscordBucketResolver(),
        );
        $headers = ['X-RateLimit-Remaining' => '4', 'X-RateLimit-Reset-After' => '3600'];

        // An ordinary payload that happens to carry a "global" field.
        $handler->handle(
            $this->responder(new Response(200, $headers, '{"id":"1","global":true}')),
            new Request('GET', 'https://discord.com/api/v10/applications/1/commands', ['Authorization' => 'Bot secret']),
            [],
        )->wait();
        $handler->handle(
            $this->responder(new Response(200, $headers)),
            new Request('GET', 'https://discord.com/api/v10/guilds/2', ['Authorization' => 'Bot secret']),
            [],
        )->wait();

        $this->assertSame(0, $this->sleeper->count());
    }

    public function test_global_budget_counts_interaction_callback_paths_outside_discord(): void
    {
        $handler = $this->handler($this->store(), new Options(
            safetyBufferSeconds: 0,
            globalLimit: new GlobalLimit(maxRequests: 1, windowSeconds: 1),
        ));
        $respond = $this->responder(new Response(204));
        $callback = new Request('POST', 'https://api.test/interactions/1/tok/callback', ['Authorization' => 'Bearer secret']);

        $handler->handle($respond, $callback, [])->wait();
        $handler->handle($respond, $callback, [])->wait();

        $this->assertSame(1, $this->sleeper->count(), 'Only a configured pattern may exempt a path.');
    }

    public function test_global_budget_skips_configured_exempt_paths(): void
    {
        $handler = $this->handler($this->store(), new Options(
            safetyBufferSeconds: 0,
            globalLimit: new GlobalLimit(maxRequests: 1, windowSeconds: 1, exemptPaths: ['#^/health$#']),
        ));
        $respond = $this->responder(new Response(204));

        for ($i = 0; $i < 3; $i++) {
            $handler->handle($respond, new Request('GET', 'https://api.test/health'), [])->wait();
        }
        $this->assertSame(0, $this->sleeper->count());

        $handler->handle($respond, new Request('GET', 'https://api.test/users'), [])->wait();
        $handler->handle($respond, new Request('GET', 'https://api.test/users'), [])->wait();

        $this->assertSame(1, $this->sleeper->count(), 'Exempt requests must not have spent the budget.');
        $this->assertEqualsWithDelta(1.0, $this->sleeper->sleeps[0], 0.001);
    }

    public function test_discord_global_limit_exempts_interaction_callbacks(): void
    {
        $handler = $this->handler($this->store(), new Options(
            safetyBufferSeconds: 0,
            globalLimit: new GlobalLimit(maxRequests: 1, windowSeconds: 1, exemptPaths: GlobalLimit::discord()->exemptPaths),
        ), Headers::discord(), new DiscordBucketResolver($this->clock));
        $respond = $this->responder(new Response(204));

        for ($i = 0; $i < 3; $i++) {
            $handler->handle(
                $respond,
                new Request('POST', 'https://discord.com/api/v10/interactions/1/tok/callback', ['Authorization' => 'Bot secret']),
                [],
            )->wait();
        }

        $this->assertSame(0, $this->sleeper->count());
    }

    public function test_streamed_body_is_left_for_the_caller(): void
    {
        $handler = $this->handler($this->store(), new Options(globalLimit: new GlobalLimit()));

        $response = $handler->handle(
            $this->responder(new Response(200, ['X-RateLimit-Remaining' => '5'], new NoSeekStream(Utils::streamFor('{"hello":"world"}')))),
            new Request('GET', 'https://api.test/download'),
            [],
        )->wait();

        $this->assertSame('{"hello":"world"}', (string) $response->getBody());
    }

    public function test_streamed_429_body_is_not_read_for_retry_after(): void
    {
        $handler = new RateLimitHandler(
            new Options(maxRetries: 0, safetyBufferSeconds: 0, throwOnRateLimit: false),
            Headers::discord(),
            $this->store(),
            new NullLogger(),
            new DiscordBucketResolver(),
            new NullLockFactory(),
            $this->clock,
            $this->sleeper,
            retryAfterResolver: new DiscordRetryAfterResolver(),
        );

        $response = $handler->handle(
            $this->responder(new Response(429, [], new NoSeekStream(Utils::streamFor('{"retry_after":2,"global":true}')))),
            new Request('GET', 'https://discord.com/api/v10/users/@me'),
            [],
        )->wait();

        $this->assertSame('{"retry_after":2,"global":true}', (string) $response->getBody());
    }

    public function test_shared_scope_429s_do_not_count_against_the_invalid_request_budget_by_default(): void
    {
        $handler = $this->handler($this->store(), new Options(
            maxRetries: 0,
            throwOnRateLimit: false,
            invalidRequestLimit: new InvalidRequestLimit(maxRequests: 1),
        ), Headers::discord(), new DiscordBucketResolver($this->clock));

        // Budget of one: the shared 429 must not spend it, so only the user 429 does.
        $this->rateLimitedInScope($handler, 'shared');
        $this->rateLimitedInScope($handler, 'user');

        $this->expectException(InvalidRequestLimitExceededException::class);

        $handler->handle($this->responder(new Response(204)), new Request('GET', 'https://discord.com/api/v10/users/@me'), [])->wait();
    }

    public function test_ignored_scopes_are_configurable(): void
    {
        $handler = $this->handler($this->store(), new Options(
            maxRetries: 0,
            throwOnRateLimit: false,
            invalidRequestLimit: new InvalidRequestLimit(maxRequests: 1, ignoredScopes: ['User']),
        ), Headers::discord(), new DiscordBucketResolver($this->clock));

        // Configured scopes match case-insensitively; "shared" now counts.
        $this->rateLimitedInScope($handler, 'user');
        $this->rateLimitedInScope($handler, 'shared');

        $this->expectException(InvalidRequestLimitExceededException::class);

        $handler->handle($this->responder(new Response(204)), new Request('GET', 'https://discord.com/api/v10/users/@me'), [])->wait();
    }

    public function test_throws_after_retries_exhausted(): void
    {
        $handler = $this->handler($this->store(), new Options(maxRetries: 1));

        $this->expectException(RateLimitExceededException::class);

        $handler->handle(
            $this->responder(new Response(429, ['Retry-After' => '2'])),
            new Request('GET', 'https://api.test/users'),
            [],
        )->wait();
    }

    public function test_does_not_throw_when_disabled(): void
    {
        $handler = $this->handler($this->store(), new Options(maxRetries: 1, throwOnRateLimit: false));

        $response = $handler->handle(
            $this->responder(new Response(429, ['Retry-After' => '2'])),
            new Request('GET', 'https://api.test/users'),
            [],
        )->wait();

        $this->assertSame(429, $response->getStatusCode());
    }

    public function test_bucket_hash_discovery_rekeys_storage(): void
    {
        $store = $this->store();
        $handler = $this->handler($store, new Options(), Headers::discord(), new DiscordBucketResolver());

        $handler->handle(
            $this->responder(new Response(200, [
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset-After' => '5',
                'X-RateLimit-Bucket' => 'abcd',
            ])),
            new Request('POST', 'https://discord.com/api/v10/channels/123/messages'),
            [],
        )->wait();

        $this->assertNotNull(
            $store->get('bucket:' . hash('sha256', "unauthenticated\0abcd\0channel_id=123")),
            'State should be stored under the discovered bucket key.',
        );
    }

    public function test_discovered_bucket_is_not_shared_between_tokens(): void
    {
        $handler = $this->handler($this->store(), new Options(), Headers::discord(), new DiscordBucketResolver());
        $response = static fn (int $remaining): ResponseInterface => new Response(200, [
            'X-RateLimit-Limit' => '5',
            'X-RateLimit-Remaining' => (string) $remaining,
            'X-RateLimit-Reset-After' => '10',
            'X-RateLimit-Bucket' => 'abc',
        ]);
        $request = static fn (string $token): RequestInterface => new Request(
            'GET',
            'https://discord.com/api/v10/channels/1/messages',
            ['Authorization' => $token],
        );

        // The first bot spends its allowance on the channel's bucket ...
        $handler->handle($this->responder($response(0)), $request('Bot first'), [])->wait();

        // ... which must not hold back a second bot on the same bucket hash.
        $handler->handle($this->responder($response(4)), $request('Bot second'), [])->wait();
        $handler->handle($this->responder($response(3)), $request('Bot second'), [])->wait();

        $this->assertSame(0, $this->sleeper->count());
    }

    public function test_github_endpoints_share_their_resource_allowance(): void
    {
        $handler = $this->handler($this->store(), new Options(), Headers::github());
        $core = static fn (int $remaining): ResponseInterface => new Response(200, [
            'X-RateLimit-Limit' => '5000',
            'X-RateLimit-Remaining' => (string) $remaining,
            'X-RateLimit-Reset' => '60',
            'X-RateLimit-Resource' => 'core',
        ]);
        $token = ['Authorization' => 'Bearer secret'];

        $handler->handle($this->responder($core(1)), new Request('GET', 'https://api.github.com/user', $token), [])->wait();
        // Another core endpoint spends the last request of the shared allowance ...
        $handler->handle($this->responder($core(0)), new Request('GET', 'https://api.github.com/repos/a/b', $token), [])->wait();
        $this->assertSame(0, $this->sleeper->count());

        // ... so the first endpoint must not believe it still has one left.
        $handler->handle($this->responder($core(4999)), new Request('GET', 'https://api.github.com/user', $token), [])->wait();

        $this->assertSame(1, $this->sleeper->count());
        $this->assertEqualsWithDelta(61.0, $this->sleeper->sleeps[0], 0.001); // 60 until reset + 1 buffer
    }

    public function test_retry_moves_to_the_bucket_revealed_by_the_429(): void
    {
        $lockFactory = new SpyLockFactory();
        $handler = $this->handler(
            $this->store(),
            new Options(safetyBufferSeconds: 0),
            Headers::discord(),
            new DiscordBucketResolver(),
            $lockFactory,
        );

        $response = $handler->handle(
            $this->queue(
                new Response(429, [
                    'Retry-After' => '2',
                    'X-RateLimit-Remaining' => '0',
                    'X-RateLimit-Reset-After' => '2',
                    'X-RateLimit-Bucket' => 'abcd',
                ]),
                new Response(200, ['X-RateLimit-Remaining' => '4', 'X-RateLimit-Reset-After' => '5', 'X-RateLimit-Bucket' => 'abcd']),
            ),
            new Request('POST', 'https://discord.com/api/v10/channels/123/messages'),
            [],
        )->wait();

        $bucketKey = 'bucket:' . hash('sha256', "unauthenticated\0abcd\0channel_id=123");

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(3, $lockFactory->keys, 'First attempt, recording its response, and the retry.');
        $this->assertNotSame($bucketKey, $lockFactory->keys[0], 'The first attempt only knows the route.');
        $this->assertSame($bucketKey, $lockFactory->keys[2], 'The retry reserves under the discovered bucket.');
    }

    public function test_lock_is_acquired_and_released(): void
    {
        $lockFactory = new SpyLockFactory();
        $handler = $this->handler($this->store(), new Options(), new Headers(), new DefaultBucketResolver(), $lockFactory);

        $handler->handle(
            $this->responder(new Response(200, ['X-RateLimit-Remaining' => '5'])),
            new Request('GET', 'https://api.test/users'),
            [],
        )->wait();

        $this->assertSame(1, $lockFactory->acquired);
        $this->assertSame(1, $lockFactory->released);
    }

    public function test_lock_is_released_even_when_exception_thrown(): void
    {
        $lockFactory = new SpyLockFactory();
        $handler = $this->handler($this->store(), new Options(maxRetries: 0), new Headers(), new DefaultBucketResolver(), $lockFactory);

        try {
            $handler->handle(
                $this->responder(new Response(429, ['Retry-After' => '1'])),
                new Request('GET', 'https://api.test/users'),
                [],
            )->wait();
        } catch (RateLimitExceededException) {
            // expected
        }

        $this->assertSame(1, $lockFactory->released);
    }

    public function test_lock_is_not_held_while_a_known_bucket_is_requested(): void
    {
        $store = $this->store();
        $request = new Request('GET', 'https://api.test/users');
        $response = new Response(200, ['X-RateLimit-Remaining' => '5', 'X-RateLimit-Reset' => '10']);
        $lockFactory = new SpyLockFactory();
        $handler = $this->handler($store, new Options(), new Headers(), new DefaultBucketResolver(), $lockFactory);

        // The first request discovers the allowance and does hold the lock.
        $handler->handle($this->responder($response), $request, [])->wait();

        $heldDuringRequest = null;
        $handler->handle(
            static function () use ($lockFactory, $response, &$heldDuringRequest): ResponseInterface {
                $heldDuringRequest = $lockFactory->acquired - $lockFactory->released;

                return $response;
            },
            $request,
            [],
        )->wait();

        $this->assertSame(0, $heldDuringRequest, 'A known bucket must allow concurrent requests.');
    }

    public function test_route_without_rate_limit_headers_is_not_probed_one_request_at_a_time(): void
    {
        $request = new Request('GET', 'https://api.test/health');
        $lockFactory = new SpyLockFactory();
        $handler = $this->handler($this->store(), new Options(), new Headers(), new DefaultBucketResolver(), $lockFactory);

        $handler->handle($this->responder(new Response(200)), $request, [])->wait();

        $this->assertSame(0, $this->heldDuringRequest($handler, $lockFactory, $request, new Response(200)));
    }

    public function test_unmetered_route_is_probed_again_later(): void
    {
        $request = new Request('GET', 'https://api.test/health');
        $lockFactory = new SpyLockFactory();
        $handler = $this->handler($this->store(), new Options(), new Headers(), new DefaultBucketResolver(), $lockFactory);

        $handler->handle($this->responder(new Response(200)), $request, [])->wait();
        $this->clock->advance(61);

        $this->assertSame(1, $this->heldDuringRequest($handler, $lockFactory, $request, new Response(200)));
    }

    public function test_response_without_rate_limit_headers_keeps_the_known_window(): void
    {
        $store = $this->store();
        $request = new Request('GET', 'https://api.test/users');

        $this->handler($store)->handle(
            $this->responder(new Response(200, ['X-RateLimit-Remaining' => '1', 'X-RateLimit-Reset' => '10'])),
            $request,
            [],
        )->wait();
        // Spends the last slot, but an error page from a proxy comes back.
        $this->handler($store)->handle($this->responder(new Response(503)), $request, [])->wait();
        $this->assertSame(0, $this->sleeper->count());

        $this->handler($store)->handle($this->responder(new Response(200, ['X-RateLimit-Remaining' => '5'])), $request, [])->wait();

        $this->assertSame(1, $this->sleeper->count(), 'The spent slot must not be handed back.');
        $this->assertEqualsWithDelta(11.0, $this->sleeper->sleeps[0], 0.001);
    }

    public function test_bucket_state_is_only_written_under_its_lock(): void
    {
        $locks = new TrackingLockFactory();
        $store = new GuardedStore($this->store(), $locks);
        $handler = $this->handler($store, new Options(), new Headers(), new DefaultBucketResolver(), $locks);
        $request = new Request('GET', 'https://api.test/users');
        $response = new Response(200, ['X-RateLimit-Remaining' => '5', 'X-RateLimit-Reset' => '10']);

        // The probe writes under the lock it already holds ...
        $handler->handle($this->responder($response), $request, [])->wait();
        // ... and a request on the known bucket, which sent without holding
        // it, takes it again to merge its response.
        $handler->handle($this->responder($response), $request, [])->wait();

        $this->assertSame([], $store->unguardedWrites);
        $this->assertSame([], $locks->held);
    }

    public function test_discovered_bucket_is_written_under_its_own_lock(): void
    {
        $locks = new TrackingLockFactory();
        $store = new GuardedStore($this->store(), $locks);
        $handler = $this->handler($store, new Options(), Headers::discord(), new DiscordBucketResolver(), $locks);
        $response = new Response(200, [
            'X-RateLimit-Remaining' => '4',
            'X-RateLimit-Reset-After' => '5',
            'X-RateLimit-Bucket' => 'abcd',
        ]);

        // The probe holds the route key's lock; the state lands on the bucket key.
        $handler->handle($this->responder($response), new Request('GET', 'https://discord.com/api/v10/channels/1/messages'), [])->wait();
        $handler->handle($this->responder($response), new Request('GET', 'https://discord.com/api/v10/channels/1/messages'), [])->wait();

        $this->assertSame([], $store->unguardedWrites);
        $this->assertSame([], $locks->held);
    }

    public function test_unmetered_route_is_recorded_under_its_lock(): void
    {
        $locks = new TrackingLockFactory();
        $store = new GuardedStore($this->store(), $locks);
        $handler = $this->handler($store, new Options(), new Headers(), new DefaultBucketResolver(), $locks);
        $request = new Request('GET', 'https://api.test/health');

        // The probe records the route as unmetered under the lock it holds;
        // the next request, sent without it, takes it to record its response.
        $handler->handle($this->responder(new Response(200)), $request, [])->wait();
        $handler->handle($this->responder(new Response(200)), $request, [])->wait();

        $this->assertSame([], $store->unguardedWrites);
        $this->assertSame([], $locks->held);
    }

    public function test_reserved_slots_count_down_within_the_window(): void
    {
        $store = $this->store();
        $request = new Request('GET', 'https://api.test/users');
        // Every response repeats the same window and allowance, as in-flight
        // siblings would; only the reservations may move it.
        $response = new Response(200, [
            'X-RateLimit-Limit' => '5',
            'X-RateLimit-Remaining' => '5',
            'X-RateLimit-Reset' => '10',
        ]);

        // One discovery request plus the five it reports as available.
        for ($i = 0; $i < 6; $i++) {
            $this->handler($store)->handle($this->responder($response), $request, [])->wait();
        }

        $this->assertSame(0, $this->sleeper->count());

        $this->handler($store)->handle($this->responder($response), $request, [])->wait();

        $this->assertSame(1, $this->sleeper->count(), 'Spent reservations must not be handed back.');
        $this->assertEqualsWithDelta(11.0, $this->sleeper->sleeps[0], 0.001);
    }

    public function test_github_primary_limit_403_is_retried_after_the_reset(): void
    {
        $this->clock->set(1_700_000_000.0);
        $handler = $this->handler($this->store(), new Options(), Headers::github(), retryStrategy: new GitHubRetryStrategy());

        $response = $handler->handle(
            $this->queue(
                new Response(403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '1700000030']),
                new Response(200, ['X-RateLimit-Remaining' => '4999', 'X-RateLimit-Reset' => '1700003600']),
            ),
            new Request('GET', 'https://api.github.com/repos/o/r'),
            [],
        )->wait();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $this->sleeper->count(), 'The retry must not wait out the same reset twice.');
        $this->assertEqualsWithDelta(31.0, $this->sleeper->sleeps[0], 0.001); // 30s to reset + 1 buffer
    }

    public function test_github_plain_403_is_returned_untouched(): void
    {
        $this->clock->set(1_700_000_000.0);
        $handler = $this->handler($this->store(), new Options(), Headers::github(), retryStrategy: new GitHubRetryStrategy());
        $forbidden = new Response(
            403,
            ['X-RateLimit-Remaining' => '4999', 'X-RateLimit-Reset' => '1700003600'],
            '{"message":"Resource not accessible by integration"}',
        );

        $response = $handler->handle(
            $this->queue($forbidden),
            new Request('GET', 'https://api.github.com/repos/o/r'),
            [],
        )->wait();

        $this->assertSame($forbidden, $response);
        $this->assertSame(0, $this->sleeper->count());
    }

    public function test_github_rate_limit_403_throws_once_retries_are_exhausted(): void
    {
        $this->clock->set(1_700_000_000.0);
        $handler = $this->handler($this->store(), new Options(), Headers::github(), retryStrategy: new GitHubRetryStrategy(maxRetries: 1));
        $limited = new Response(403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '1700000010']);

        try {
            $handler->handle($this->responder($limited), new Request('GET', 'https://api.github.com/repos/o/r'), [])->wait();
            $this->fail('Expected RateLimitExceededException.');
        } catch (RateLimitExceededException $e) {
            $this->assertSame($limited, $e->getResponse());
        }

        $this->assertSame(1, $this->sleeper->count());
    }

    public function test_strategy_delay_gets_the_safety_buffer(): void
    {
        $handler = $this->handler(
            $this->store(),
            retryStrategy: new ExponentialBackoffRetryStrategy(
                new StatusCodeRetryStrategy(maxRetries: 2),
                baseSeconds: 2.0,
                random: static fn (): float => 1.0,
            ),
        );

        $response = $handler->handle(
            $this->queue(new Response(429), new Response(429), new Response(200)),
            new Request('GET', 'https://api.test/users'),
            [],
        )->wait();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([3.0, 5.0], $this->sleeper->sleeps); // 2s and 4s backoff + 1 buffer each
    }

    public function test_custom_parser_supplies_the_rate_limit(): void
    {
        $store = $this->store();
        $request = new Request('GET', 'https://api.test/users');
        // The response itself says nothing; only the parser knows the bucket is spent.
        $parser = new StubRateLimitParser([new RateLimit(limit: 5, remaining: 0, reset: 1_010.0)]);
        $handler = $this->handler($store, parser: $parser);

        $handler->handle($this->responder(new Response(200)), $request, [])->wait();
        $handler->handle($this->responder(new Response(200)), $request, [])->wait();

        $this->assertSame(2, $parser->calls);
        $this->assertSame(1, $this->sleeper->count());
        $this->assertEqualsWithDelta(11.0, $this->sleeper->sleeps[0], 0.001);
    }

    public function test_null_parse_skips_persisting(): void
    {
        $store = $this->store();
        $request = new Request('GET', 'https://api.test/users');
        // Headers the default parser would store as an exhausted bucket.
        $exhausted = new Response(200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '10']);
        $parser = new StubRateLimitParser([null, null]);
        $handler = $this->handler($store, parser: $parser);

        $handler->handle($this->responder($exhausted), $request, [])->wait();
        $handler->handle($this->responder($exhausted), $request, [])->wait();

        $this->assertSame(2, $parser->calls);
        $this->assertSame(0, $this->sleeper->count());
    }

    public function test_null_parse_keeps_the_stored_state(): void
    {
        $store = $this->store();
        $request = new Request('GET', 'https://api.test/users');
        $parser = new StubRateLimitParser([new RateLimit(limit: 2, remaining: 1, reset: 1_010.0), null]);
        $handler = $this->handler($store, parser: $parser);
        $respond = $this->responder(new Response(200));

        // Discovers one slot left, then spends it on a response that reports nothing.
        $handler->handle($respond, $request, [])->wait();
        $handler->handle($respond, $request, [])->wait();
        $this->assertSame(0, $this->sleeper->count());

        // Had the second response overwritten the state, this would not wait.
        $handler->handle($respond, $request, [])->wait();

        $this->assertSame(1, $this->sleeper->count());
        $this->assertEqualsWithDelta(11.0, $this->sleeper->sleeps[0], 0.001);
    }

    public function test_bucket_header_still_rekeys_when_the_parser_does_not_report_one(): void
    {
        $store = $this->store();
        $handler = $this->handler(
            $store,
            headers: Headers::discord(),
            resolver: new DiscordBucketResolver(),
            parser: new StubRateLimitParser([new RateLimit(limit: 5, remaining: 0, reset: 1_005.0)]),
        );

        $handler->handle(
            $this->responder(new Response(200, ['X-RateLimit-Bucket' => 'abcd'])),
            new Request('POST', 'https://discord.com/api/v10/channels/123/messages'),
            [],
        )->wait();

        $stored = $store->get('bucket:' . hash('sha256', "unauthenticated\0abcd\0channel_id=123"));

        $this->assertNotNull($stored, 'State should be stored under the discovered bucket key.');
        $this->assertSame(0, $stored['remaining']);
    }

    public function test_client_side_limit_waits_include_the_safety_buffer(): void
    {
        $handler = $this->handler($this->store(), new Options(
            safetyBufferSeconds: 0.5,
            limits: [Limit::perSecond(1, LimitAlgorithm::FixedWindow)],
        ));
        $respond = $this->responder(new Response(200));
        $request = new Request('GET', 'https://api.test/users');

        $handler->handle($respond, $request, [])->wait();
        $handler->handle($respond, $request, [])->wait();

        $this->assertSame(1, $this->sleeper->count());
        $this->assertEqualsWithDelta(1.5, $this->sleeper->sleeps[0], 0.001);
    }

    public function test_client_side_wait_beyond_the_maximum_throws_before_sending(): void
    {
        $handler = $this->handler($this->store(), new Options(
            safetyBufferSeconds: 0,
            maxDelaySeconds: 10,
            limits: [
                Limit::perSecond(2, LimitAlgorithm::FixedWindow, name: 'burst'),
                Limit::perHour(
                    1,
                    LimitAlgorithm::FixedWindow,
                    appliesTo: static fn (RequestInterface $request): bool => $request->getMethod() === 'POST',
                    name: 'writes',
                ),
            ],
        ));
        $sent = 0;
        $respond = static function () use (&$sent): ResponseInterface {
            $sent++;

            return new Response(200);
        };
        $write = new Request('POST', 'https://api.test/messages');

        $handler->handle($respond, $write, [])->wait();

        try {
            $handler->handle($respond, $write, [])->wait();
            $this->fail('An hour-long wait exceeds maxDelaySeconds.');
        } catch (RateLimitDelayExceededException $e) {
            $this->assertEqualsWithDelta(3600.0, $e->retryAfter, 0.001);
        }

        // The refused write took nothing from the per-second budget either.
        $handler->handle($respond, new Request('GET', 'https://api.test/messages'), [])->wait();

        $this->assertSame(2, $sent);
        $this->assertSame(0, $this->sleeper->count());
    }

    public function test_retries_count_against_client_side_limits(): void
    {
        $handler = $this->handler($this->store(), new Options(
            safetyBufferSeconds: 0,
            limits: [Limit::perSecond(1, LimitAlgorithm::FixedWindow)],
        ));

        $response = $handler->handle(
            $this->queue(new Response(429, ['Retry-After' => '0']), new Response(200)),
            new Request('GET', 'https://api.test/users'),
            [],
        )->wait();

        // The immediate retry is another request the API counts.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $this->sleeper->count());
        $this->assertEqualsWithDelta(0.0, $this->sleeper->sleeps[0], 0.001);
        $this->assertEqualsWithDelta(1.0, $this->sleeper->sleeps[1], 0.001);
    }

    public function test_disabled_request_goes_straight_to_the_next_handler(): void
    {
        $store = new SpyStore($this->store());
        $lockFactory = new SpyLockFactory();
        $handler = $this->handler($store, new Options(
            globalLimit: new GlobalLimit(maxRequests: 1, windowSeconds: 1),
            invalidRequestLimit: new InvalidRequestLimit(maxRequests: 1, windowSeconds: 600),
        ), lockFactory: $lockFactory);
        $request = new Request('GET', 'https://api.test/users', ['Authorization' => 'Bot secret']);

        // Spend the bucket, the global budget and the invalid-request budget.
        $handler->handle(
            $this->responder(new Response(401, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '10'])),
            $request,
            [],
        )->wait();
        $store->reads = $store->writes = [];
        $lockFactory->acquired = 0;

        $response = $handler->handle(
            $this->queue(new Response(429, ['Retry-After' => '5'])),
            $request,
            ['rate_limit' => ['enabled' => false]],
        )->wait();

        $this->assertSame(429, $response->getStatusCode(), 'Neither retried nor thrown.');
        $this->assertSame(0, $this->sleeper->count());
        $this->assertSame([], $store->writes);
        $this->assertSame([], $store->reads);
        $this->assertSame(0, $lockFactory->acquired);
    }

    public function test_bucket_option_shares_state_between_routes(): void
    {
        $handler = $this->handler($this->store());
        $exhausted = $this->responder(new Response(200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '10']));
        $shared = ['rate_limit' => ['bucket' => 'bulk-writes']];

        $handler->handle($exhausted, new Request('POST', 'https://api.test/users'), $shared)->wait();

        // A route that does not name the bucket keeps its own state, and the
        // named bucket is still scoped to the identity that spent it ...
        $handler->handle($exhausted, new Request('GET', 'https://api.test/users'), [])->wait();
        $handler->handle($exhausted, new Request('PUT', 'https://api.test/orders', ['Authorization' => 'Bot other']), $shared)->wait();
        $this->assertSame(0, $this->sleeper->count());

        // ... while any other route naming it waits on the spent bucket.
        $handler->handle($exhausted, new Request('DELETE', 'https://api.test/orders/1'), $shared)->wait();

        $this->assertSame(1, $this->sleeper->count());
        $this->assertEqualsWithDelta(11.0, $this->sleeper->sleeps[0], 0.001);
    }

    public function test_identity_option_isolates_state_without_storing_it_raw(): void
    {
        $store = new SpyStore($this->store());
        $lockFactory = new SpyLockFactory();
        $handler = $this->handler($store, new Options(
            globalLimit: new GlobalLimit(maxRequests: 10, windowSeconds: 1),
            invalidRequestLimit: new InvalidRequestLimit(),
        ), lockFactory: $lockFactory);
        // One credential for every call: only the identity option tells them apart.
        $request = new Request('GET', 'https://api.test/users', ['Authorization' => 'Bot shared']);
        $exhausted = $this->responder(new Response(200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '10']));

        $handler->handle($exhausted, $request, ['rate_limit' => ['identity' => 'tenant-a']])->wait();
        $handler->handle($exhausted, $request, ['rate_limit' => ['identity' => 'tenant-b']])->wait();
        $handler->handle($exhausted, $request, [])->wait();
        $this->assertSame(0, $this->sleeper->count());

        $handler->handle($exhausted, $request, ['rate_limit' => ['identity' => 'tenant-a']])->wait();
        $this->assertSame(1, $this->sleeper->count());

        $keys = [...$store->keys(), ...$lockFactory->keys];
        $this->assertNotEmpty($keys);
        foreach ($keys as $key) {
            $this->assertStringNotContainsString('tenant-', $key);
        }
    }

    public function test_max_delay_option_throws_instead_of_sleeping(): void
    {
        $store = $this->store();
        $request = new Request('GET', 'https://api.test/search');
        $this->handler($store)->handle(
            $this->responder(new Response(200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '10'])),
            $request,
            [],
        )->wait();

        try {
            $this->handler($store)->handle($this->unreachable(), $request, ['rate_limit' => ['max_delay' => 0]])->wait();
            $this->fail('The wait should have been refused.');
        } catch (RateLimitDelayExceededException $e) {
            $this->assertEqualsWithDelta(11.0, $e->retryAfter, 0.001);
        }

        $this->assertSame(0, $this->sleeper->count());
    }

    public function test_max_delay_option_replaces_the_configured_cap(): void
    {
        $request = new Request('GET', 'https://api.test/reports');
        $exhausted = $this->responder(new Response(200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '10']));
        $handler = $this->handler($this->store(), new Options(maxDelaySeconds: 5));

        $handler->handle($exhausted, $request, [])->wait();

        // Each wait is 11s: over the configured 5s cap, within the overrides.
        $handler->handle($exhausted, $request, ['rate_limit' => ['max_delay' => null]])->wait();
        $handler->handle($exhausted, $request, ['rate_limit' => ['max_delay' => 30]])->wait();
        $this->assertSame(2, $this->sleeper->count());

        $this->expectException(RateLimitDelayExceededException::class);
        $handler->handle($exhausted, $request, [])->wait();
    }

    public function test_max_retries_option_zero_returns_the_429(): void
    {
        $handler = $this->handler($this->store(), new Options(maxRetries: 3, throwOnRateLimit: false));

        $response = $handler->handle(
            $this->queue(new Response(429, ['Retry-After' => '2'])),
            new Request('GET', 'https://api.test/users'),
            ['rate_limit' => ['max_retries' => 0]],
        )->wait();

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame(0, $this->sleeper->count());
    }

    public function test_max_retries_option_zero_throws_when_configured_to(): void
    {
        $handler = $this->handler($this->store(), new Options(maxRetries: 3));

        try {
            $handler->handle(
                $this->queue(new Response(429, ['Retry-After' => '2'])),
                new Request('GET', 'https://api.test/users'),
                ['rate_limit' => ['max_retries' => 0]],
            )->wait();
            $this->fail('The 429 should have been thrown.');
        } catch (RateLimitExceededException $e) {
            $this->assertSame(429, $e->getResponse()?->getStatusCode());
        }

        $this->assertSame(0, $this->sleeper->count());
    }

    public function test_max_retries_option_can_raise_the_configured_limit(): void
    {
        $handler = $this->handler($this->store(), new Options(maxRetries: 0, safetyBufferSeconds: 0));

        $response = $handler->handle(
            $this->queue(
                new Response(429, ['Retry-After' => '1']),
                new Response(429, ['Retry-After' => '2']),
                new Response(200),
            ),
            new Request('GET', 'https://api.test/users'),
            ['rate_limit' => ['max_retries' => 2]],
        )->wait();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([1.0, 2.0], $this->sleeper->sleeps);
    }

    public function test_rate_limit_option_is_not_passed_to_the_next_handler(): void
    {
        $received = [];
        $next = static function (RequestInterface $request, array $options) use (&$received): ResponseInterface {
            $received[] = $options;

            return new Response(200);
        };
        $handler = $this->handler($this->store());
        $request = new Request('GET', 'https://api.test/users');

        $handler->handle($next, $request, ['timeout' => 5, 'rate_limit' => ['max_retries' => 1]])->wait();
        $handler->handle($next, $request, ['timeout' => 5, 'rate_limit' => ['enabled' => false]])->wait();

        $this->assertSame([['timeout' => 5], ['timeout' => 5]], $received);
    }

    public function test_invalid_rate_limit_option_throws_before_sending(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown "rate_limit" request option "max_delays"');

        $this->handler($this->store())->handle(
            $this->unreachable(),
            new Request('GET', 'https://api.test/users'),
            ['rate_limit' => ['max_delays' => 0]],
        )->wait();
    }

    private function store(): InMemoryStore
    {
        return new InMemoryStore($this->clock);
    }

    private function handler(
        StoreInterface $store,
        Options $options = new Options(),
        Headers $headers = new Headers(),
        ?BucketResolverInterface $resolver = null,
        ?LockFactoryInterface $lockFactory = null,
        ?RetryStrategyInterface $retryStrategy = null,
        ?RateLimitParserInterface $parser = null,
    ): RateLimitHandler {
        return new RateLimitHandler(
            $options,
            $headers,
            $store,
            new NullLogger(),
            $resolver ?? new DefaultBucketResolver(),
            $lockFactory ?? new NullLockFactory(),
            $this->clock,
            $this->sleeper,
            retryStrategy: $retryStrategy,
            parser: $parser,
        );
    }

    /**
     * How many locks were held while the request itself was in flight.
     */
    private function heldDuringRequest(
        RateLimitHandler $handler,
        SpyLockFactory $lockFactory,
        RequestInterface $request,
        ResponseInterface $response,
    ): int {
        $held = null;
        $handler->handle(
            static function () use ($lockFactory, $response, &$held): ResponseInterface {
                $held = $lockFactory->acquired - $lockFactory->released;

                return $response;
            },
            $request,
            [],
        )->wait();

        TestCase::assertIsInt($held);

        return $held;
    }

    private function rateLimitedInScope(RateLimitHandler $handler, string $scope): void
    {
        $handler->handle(
            $this->responder(new Response(429, ['X-RateLimit-Scope' => $scope])),
            new Request('GET', 'https://discord.com/api/v10/channels/1'),
            [],
        )->wait();
    }

    private function responder(ResponseInterface $response): callable
    {
        return static fn (RequestInterface $request, array $options): ResponseInterface => $response;
    }

    private function unreachable(): callable
    {
        return static function (RequestInterface $request, array $options): ResponseInterface {
            TestCase::fail('The request should not have been sent.');
        };
    }

    private function queue(ResponseInterface ...$responses): callable
    {
        $queue = $responses;

        return static function (RequestInterface $request, array $options) use (&$queue): ResponseInterface {
            $response = array_shift($queue);
            TestCase::assertNotNull($response);

            return $response;
        };
    }
}
