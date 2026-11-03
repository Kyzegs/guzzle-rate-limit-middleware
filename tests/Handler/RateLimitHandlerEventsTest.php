<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Handler;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Config\GlobalLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\Config\InvalidRequestLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\BucketResolverInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\StoreInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Event\DelayReason;
use Kyzegs\GuzzleRateLimitMiddleware\Event\InvalidRequestLimitReached;
use Kyzegs\GuzzleRateLimitMiddleware\Event\RateLimitExceeded;
use Kyzegs\GuzzleRateLimitMiddleware\Event\RateLimitUpdated;
use Kyzegs\GuzzleRateLimitMiddleware\Event\RequestDelayed;
use Kyzegs\GuzzleRateLimitMiddleware\Event\RetryScheduled;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\InvalidRequestLimitExceededException;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\RateLimitExceededException;
use Kyzegs\GuzzleRateLimitMiddleware\Handler\RateLimitHandler;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\DefaultBucketResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\DiscordBucketResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Support\NullLockFactory;
use Kyzegs\GuzzleRateLimitMiddleware\Testing\FakeClock;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\RecordingEventDispatcher;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\RecordingLogger;
use Kyzegs\GuzzleRateLimitMiddleware\Testing\RecordingSleeper;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use RuntimeException;

final class RateLimitHandlerEventsTest extends TestCase
{
    private FakeClock $clock;

    private RecordingSleeper $sleeper;

    private RecordingEventDispatcher $events;

    protected function setUp(): void
    {
        $this->clock = new FakeClock(1_000.0);
        $this->sleeper = new RecordingSleeper($this->clock);
        $this->events = new RecordingEventDispatcher();
    }

    public function test_announces_a_bucket_delay_before_sleeping(): void
    {
        $sleepsWhenAnnounced = [];
        $events = new RecordingEventDispatcher(function (object $event) use (&$sleepsWhenAnnounced): void {
            if ($event instanceof RequestDelayed) {
                $sleepsWhenAnnounced[] = $this->sleeper->count();
            }
        });
        $handler = $this->handler($this->store(), events: $events);
        $request = new Request('GET', 'https://api.test/users');
        $exhausted = $this->responder(new Response(200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '10']));

        $handler->handle($exhausted, $request, [])->wait();
        $handler->handle($exhausted, $request, [])->wait();

        $delays = $events->ofType(RequestDelayed::class);
        $this->assertCount(1, $delays);
        $this->assertSame([0], $sleepsWhenAnnounced, 'The delay must be announced before it is slept.');
        $this->assertSame($request, $delays[0]->request);
        $this->assertSame(DelayReason::Bucket, $delays[0]->reason);
        $this->assertEqualsWithDelta(11.0, $delays[0]->delay, 0.001); // 10s + 1s buffer
        $this->assertSame($this->sleeper->sleeps[0], $delays[0]->delay, 'The event must carry exactly what is slept.');
        $this->assertSame($events->ofType(RateLimitUpdated::class)[0]->key, $delays[0]->key);
        $this->assertStringNotContainsString('/users', $delays[0]->key);
    }

    public function test_announces_a_global_budget_delay(): void
    {
        $store = $this->store();
        $handler = $this->handler($store, new Options(
            safetyBufferSeconds: 0,
            globalLimit: new GlobalLimit(maxRequests: 1, windowSeconds: 1),
        ));
        $respond = $this->responder(new Response(200, ['X-RateLimit-Remaining' => '10']));
        $second = new Request('GET', 'https://api.test/two', ['Authorization' => 'Bot secret']);

        $handler->handle($respond, new Request('GET', 'https://api.test/one', ['Authorization' => 'Bot secret']), [])->wait();
        $handler->handle($respond, $second, [])->wait();

        $delays = $this->events->ofType(RequestDelayed::class);
        $this->assertCount(1, $delays);
        $this->assertSame($second, $delays[0]->request);
        $this->assertSame(DelayReason::GlobalLimit, $delays[0]->reason);
        $this->assertEqualsWithDelta(1.0, $delays[0]->delay, 0.001);
        $this->assertNotNull($store->get($delays[0]->key), 'The key must be where the budget is stored.');
        $this->assertStringNotContainsString('secret', $delays[0]->key);
    }

    public function test_announces_each_retry_before_sleeping(): void
    {
        $sleepsWhenAnnounced = [];
        $events = new RecordingEventDispatcher(function (object $event) use (&$sleepsWhenAnnounced): void {
            if ($event instanceof RetryScheduled) {
                $sleepsWhenAnnounced[] = $this->sleeper->count();
            }
        });
        $request = new Request('GET', 'https://api.test/users');
        $first = new Response(429, ['Retry-After' => '4']);
        $second = new Response(429, ['Retry-After' => '2']);

        $this->handler($this->store(), events: $events)->handle(
            $this->queue($first, $second, new Response(200)),
            $request,
            [],
        )->wait();

        $retries = $events->ofType(RetryScheduled::class);
        $this->assertCount(2, $retries);
        $this->assertSame([0, 1], $sleepsWhenAnnounced, 'Each retry must be announced before its sleep.');

        $this->assertSame($request, $retries[0]->request);
        $this->assertSame($first, $retries[0]->response);
        $this->assertSame(1, $retries[0]->attempt);
        $this->assertSame(3, $retries[0]->maxRetries);
        $this->assertEqualsWithDelta(5.0, $retries[0]->delay, 0.001); // 4s + 1s buffer
        $this->assertFalse($retries[0]->global);

        $this->assertSame($second, $retries[1]->response);
        $this->assertSame(2, $retries[1]->attempt);
        $this->assertEqualsWithDelta(3.0, $retries[1]->delay, 0.001);

        $this->assertSame([], $events->ofType(RateLimitExceeded::class));
    }

    public function test_announces_the_exceeded_limit_before_throwing(): void
    {
        $handler = $this->handler(
            $this->store(),
            new Options(maxRetries: 1, safetyBufferSeconds: 0),
            Headers::discord(),
            new DiscordBucketResolver(),
        );
        $request = new Request('POST', 'https://discord.com/api/v10/channels/1/messages', ['Authorization' => 'Bot secret']);
        $limited = new Response(429, ['Retry-After' => '2', 'X-RateLimit-Global' => 'true']);

        $caught = null;
        try {
            $handler->handle($this->responder($limited), $request, [])->wait();
        } catch (RateLimitExceededException $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught);
        $this->assertTrue($this->events->ofType(RetryScheduled::class)[0]->global);

        $exceeded = $this->events->ofType(RateLimitExceeded::class);
        $this->assertCount(1, $exceeded);
        $this->assertSame($request, $exceeded[0]->request);
        $this->assertSame($limited, $exceeded[0]->response);
        $this->assertSame(2.0, $exceeded[0]->retryAfter);
        $this->assertTrue($exceeded[0]->global);
        $this->assertSame($caught->getRetryAfter(), $exceeded[0]->retryAfter);
        $this->assertSame($caught->isGlobal(), $exceeded[0]->global);
    }

    public function test_announces_the_state_as_persisted(): void
    {
        $store = $this->store();
        $handler = $this->handler($store);
        $request = new Request('GET', 'https://api.test/users');
        // Both responses repeat the same window and allowance, as in-flight
        // siblings would; the second must not hand back the reserved slot.
        $respond = $this->responder(new Response(200, [
            'X-RateLimit-Limit' => '5',
            'X-RateLimit-Remaining' => '5',
            'X-RateLimit-Reset' => '10',
        ]));

        $handler->handle($respond, $request, [])->wait();
        $handler->handle($respond, $request, [])->wait();

        $updates = $this->events->ofType(RateLimitUpdated::class);
        $this->assertCount(2, $updates);
        $this->assertSame($request, $updates[1]->request);
        $this->assertSame(5, $updates[1]->rateLimit->limit);
        $this->assertSame(4, $updates[1]->rateLimit->remaining, 'The stored count, not the raw header.');
        $this->assertEqualsWithDelta(1_010.0, $updates[1]->rateLimit->reset, 0.001);
        $this->assertSame($store->get($updates[1]->key), $updates[1]->rateLimit->toArray());
    }

    public function test_announces_the_rekeyed_bucket_after_discovery(): void
    {
        $handler = $this->handler($this->store(), new Options(), Headers::discord(), new DiscordBucketResolver());

        $handler->handle(
            $this->responder(new Response(200, [
                'X-RateLimit-Remaining' => '3',
                'X-RateLimit-Reset-After' => '5',
                'X-RateLimit-Bucket' => 'abcd',
            ])),
            new Request('POST', 'https://discord.com/api/v10/channels/123/messages'),
            [],
        )->wait();

        $updates = $this->events->ofType(RateLimitUpdated::class);
        $this->assertCount(1, $updates);
        $this->assertSame('bucket:' . hash('sha256', "unauthenticated\0abcd\0channel_id=123"), $updates[0]->key);
        $this->assertSame('abcd', $updates[0]->rateLimit->bucketHash);
    }

    public function test_announces_the_invalid_request_limit_before_throwing(): void
    {
        $handler = $this->handler($this->store(), new Options(
            invalidRequestLimit: new InvalidRequestLimit(maxRequests: 1, windowSeconds: 600, statusCodes: [401]),
        ));
        $request = new Request('GET', 'https://api.test/users', ['Authorization' => 'Bot secret']);

        $handler->handle($this->responder(new Response(401, ['X-RateLimit-Remaining' => '5'])), $request, [])->wait();
        $this->assertSame([], $this->events->ofType(InvalidRequestLimitReached::class));

        $caught = null;
        try {
            $handler->handle($this->responder(new Response(200)), $request, [])->wait();
        } catch (InvalidRequestLimitExceededException $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught);
        $reached = $this->events->ofType(InvalidRequestLimitReached::class);
        $this->assertCount(1, $reached);
        $this->assertSame($request, $reached[0]->request);
        $this->assertEqualsWithDelta(600.0, $reached[0]->retryAfter, 0.001);
        $this->assertSame($caught->retryAfter, $reached[0]->retryAfter);
        $this->assertCount(1, $this->events->ofType(RateLimitUpdated::class), 'A refused request is never sent.');
    }

    public function test_behaves_identically_without_a_dispatcher(): void
    {
        $withEvents = $this->exerciseEveryDispatchPoint($this->events);
        $withoutEvents = $this->exerciseEveryDispatchPoint(null);

        // Only meaningful if the scenario actually reaches every dispatch point.
        $types = array_values(array_unique(array_map(get_class(...), $this->events->events)));
        sort($types);
        $this->assertSame([
            InvalidRequestLimitReached::class,
            RateLimitExceeded::class,
            RateLimitUpdated::class,
            RequestDelayed::class,
            RetryScheduled::class,
        ], $types);

        $this->assertSame($withEvents, $withoutEvents);
    }

    public function test_logs_a_bucket_delay_with_structured_context(): void
    {
        $logger = new RecordingLogger();
        $handler = $this->handler($this->store(), logger: $logger);
        $request = new Request('GET', 'https://api.test/users');
        $exhausted = $this->responder(new Response(200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '10']));

        $handler->handle($exhausted, $request, [])->wait();
        $handler->handle($exhausted, $request, [])->wait();

        $key = $this->events->ofType(RequestDelayed::class)[0]->key;
        $this->assertSame([[
            'level' => LogLevel::DEBUG,
            'message' => sprintf('Rate limit bucket "%s" exhausted; sleeping 11.00s until reset.', $key),
            'context' => ['key' => $key, 'delay' => 11.0, 'reason' => 'bucket', 'method' => 'GET'],
        ]], $logger->records);
    }

    public function test_logs_a_global_delay_with_structured_context(): void
    {
        $logger = new RecordingLogger();
        $handler = $this->handler(
            $this->store(),
            new Options(safetyBufferSeconds: 0, globalLimit: new GlobalLimit(maxRequests: 1, windowSeconds: 1)),
            logger: $logger,
        );
        $respond = $this->responder(new Response(200, ['X-RateLimit-Remaining' => '10']));

        $handler->handle($respond, new Request('GET', 'https://api.test/one'), [])->wait();
        $handler->handle($respond, new Request('POST', 'https://api.test/two'), [])->wait();

        $key = $this->events->ofType(RequestDelayed::class)[0]->key;
        $this->assertSame([[
            'level' => LogLevel::DEBUG,
            'message' => sprintf('Global rate limit "%s" exhausted; sleeping 1.00s until reset.', $key),
            'context' => ['key' => $key, 'delay' => 1.0, 'reason' => 'global', 'method' => 'POST'],
        ]], $logger->records);
    }

    public function test_logs_a_retry_with_structured_context_but_not_the_uri(): void
    {
        $logger = new RecordingLogger();
        $handler = $this->handler($this->store(), logger: $logger);

        $handler->handle(
            $this->queue(new Response(429, ['Retry-After' => '4']), new Response(200)),
            new Request('GET', 'https://api.test/users/42'),
            [],
        )->wait();

        // The message keeps its URI; the context must not copy it into indexed fields.
        $this->assertSame([[
            'level' => LogLevel::WARNING,
            'message' => 'Received 429 for GET api.test/users/{id}. Retry 1/3 in 5.00s.',
            'context' => [
                'method' => 'GET',
                'status' => 429,
                'attempt' => 1,
                'max_retries' => 3,
                'delay' => 5.0,
                'global' => false,
            ],
        ]], $logger->records);
    }

    public function test_delay_logs_never_carry_the_request_path(): void
    {
        $logger = new RecordingLogger();
        $handler = $this->handler(
            $this->store(),
            new Options(safetyBufferSeconds: 0, globalLimit: new GlobalLimit(maxRequests: 1, windowSeconds: 10)),
            Headers::discord(),
            new DiscordBucketResolver(),
            logger: $logger,
        );
        // Discord webhook tokens travel in the path, so only opaque keys may be logged.
        $request = new Request('POST', 'https://discord.com/api/v10/webhooks/123/s3cr3t-t0ken');
        $exhausted = $this->responder(new Response(200, [
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset-After' => '1',
            'X-RateLimit-Bucket' => 'abcd',
        ]));

        $handler->handle($exhausted, $request, [])->wait();
        $handler->handle($exhausted, $request, [])->wait();

        $this->assertSame(['bucket', 'global'], array_column(array_column($logger->records, 'context'), 'reason'));

        foreach ($logger->records as $record) {
            $logged = $record['message'] . json_encode($record['context'], JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('s3cr3t-t0ken', $logged);
            $this->assertStringNotContainsString('webhooks', $logged);
        }
    }

    /**
     * Drives one handler through a bucket delay, a global delay, a retry, an
     * exhausted retry and a refused request, and reports what the caller could
     * observe: the sleeps and the exceptions.
     *
     * @return array{sleeps: list<float>, thrown: list<class-string>}
     */
    private function exerciseEveryDispatchPoint(?EventDispatcherInterface $events): array
    {
        $clock = new FakeClock(1_000.0);
        $sleeper = new RecordingSleeper($clock);
        $handler = new RateLimitHandler(
            new Options(
                maxRetries: 1,
                safetyBufferSeconds: 0,
                globalLimit: new GlobalLimit(maxRequests: 1, windowSeconds: 1),
                invalidRequestLimit: new InvalidRequestLimit(maxRequests: 1, statusCodes: [429]),
            ),
            new Headers(),
            new InMemoryStore($clock),
            new NullLogger(),
            new DefaultBucketResolver(),
            new NullLockFactory(),
            $clock,
            $sleeper,
            events: $events,
        );
        $exhausted = new Response(200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '10']);
        $open = new Response(200, ['X-RateLimit-Remaining' => '5', 'X-RateLimit-Reset' => '10']);
        $limited = new Response(429, ['Retry-After' => '1']);

        $calls = [
            ['https://api.test/a', $exhausted], // records a spent bucket
            ['https://api.test/b', $open],      // waits out the global budget
            ['https://api.test/a', $open],      // waits out the spent bucket
            ['https://api.test/c', $limited],   // retries, then gives up
            ['https://api.test/d', $open],      // refused: invalid budget spent
        ];

        $thrown = [];
        foreach ($calls as [$uri, $response]) {
            try {
                $handler->handle($this->responder($response), new Request('GET', $uri), [])->wait();
            } catch (RuntimeException $exception) {
                $thrown[] = $exception::class;
            }
        }

        return ['sleeps' => $sleeper->sleeps, 'thrown' => $thrown];
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
        ?LoggerInterface $logger = null,
        ?EventDispatcherInterface $events = null,
    ): RateLimitHandler {
        return new RateLimitHandler(
            $options,
            $headers,
            $store,
            $logger ?? new NullLogger(),
            $resolver ?? new DefaultBucketResolver(),
            new NullLockFactory(),
            $this->clock,
            $this->sleeper,
            events: $events ?? $this->events,
        );
    }

    private function responder(ResponseInterface $response): callable
    {
        return static fn (RequestInterface $request, array $options): ResponseInterface => $response;
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
