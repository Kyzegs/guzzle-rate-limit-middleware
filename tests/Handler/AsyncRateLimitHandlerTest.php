<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Handler;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Is;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\Config\InvalidRequestLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockFactoryInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\InvalidRequestLimitExceededException;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\RateLimitExceededException;
use Kyzegs\GuzzleRateLimitMiddleware\Handler\RateLimitHandler;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\DefaultBucketResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Support\NullLockFactory;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\ExclusiveLockFactory;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\FakeClock;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\RecordingSleeper;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\SpyLockFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The handler must hand back the inner handler's promise chain untouched by
 * waits, so that async callers keep their transfers in flight together.
 *
 * The inner handler here returns promises that only complete when waited on
 * and logs both events, so the tests can see exactly when each transfer is
 * sent and when something waits for it.
 */
final class AsyncRateLimitHandlerTest extends TestCase
{
    private FakeClock $clock;

    private RecordingSleeper $sleeper;

    private InMemoryStore $store;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->clock = new FakeClock(1_000.0);
        $this->sleeper = new RecordingSleeper($this->clock);
        $this->store = new InMemoryStore($this->clock);
    }

    public function test_get_async_returns_before_any_transfer_is_waited(): void
    {
        $client = $this->client($this->transport(
            new Response(200, ['X-RateLimit-Remaining' => '5']),
            new Response(200, ['X-RateLimit-Remaining' => '5']),
        ));

        $first = $client->getAsync('https://api.test/first');
        $second = $client->getAsync('https://api.test/second');

        $this->assertSame(['send /first', 'send /second'], $this->log, 'Both requests go out before either is waited on.');

        $this->assertSame(200, $first->wait()->getStatusCode());
        $this->assertSame(200, $second->wait()->getStatusCode());
        $this->assertSame(['send /first', 'send /second', 'wait /first', 'wait /second'], $this->log);
    }

    public function test_utils_all_resolves_every_request_and_persists_their_state(): void
    {
        $locks = new SpyLockFactory();
        $client = $this->client($this->transport(
            new Response(200, ['X-RateLimit-Remaining' => '7', 'X-RateLimit-Reset' => '60']),
            new Response(200, ['X-RateLimit-Remaining' => '8', 'X-RateLimit-Reset' => '60']),
            new Response(200, ['X-RateLimit-Remaining' => '9', 'X-RateLimit-Reset' => '60']),
        ), lockFactory: $locks);

        $promises = [
            $client->getAsync('https://api.test/a'),
            $client->getAsync('https://api.test/b'),
            $client->getAsync('https://api.test/c'),
        ];

        $this->assertSame(['send /a', 'send /b', 'send /c'], $this->log);

        $responses = Utils::all($promises)->wait();

        $this->assertCount(3, $responses);
        foreach ($responses as $response) {
            $this->assertSame(200, $response->getStatusCode());
        }

        // Each unknown bucket was probed under its own key; every response
        // has been recorded there.
        $this->assertCount(3, $locks->keys);
        $this->assertSame(7, $this->store->get($locks->keys[0])['remaining'] ?? null);
        $this->assertSame(8, $this->store->get($locks->keys[1])['remaining'] ?? null);
        $this->assertSame(9, $this->store->get($locks->keys[2])['remaining'] ?? null);
        $this->assertSame(3, $locks->acquired);
        $this->assertSame(3, $locks->released);
        $this->assertSame(0, $this->sleeper->count());
    }

    public function test_requests_to_an_unknown_bucket_queue_behind_the_probe_instead_of_its_lock(): void
    {
        $locks = new ExclusiveLockFactory();
        $response = new Response(200, [
            'X-RateLimit-Limit' => '5',
            'X-RateLimit-Remaining' => '5',
            'X-RateLimit-Reset' => '10',
        ]);
        $client = $this->client($this->transport($response, $response, $response), lockFactory: $locks);

        $promises = [
            $client->getAsync('https://api.test/users'),
            $client->getAsync('https://api.test/users'),
            $client->getAsync('https://api.test/users'),
        ];

        // Only the probe is out; the others wait for it to reveal the allowance.
        $this->assertSame(['send /users'], $this->log);
        $this->assertSame([$locks->keys[0] => true], $locks->held);

        foreach (Utils::all($promises)->wait() as $settled) {
            $this->assertSame(200, $settled->getStatusCode());
        }

        $this->assertSame(
            ['send /users', 'wait /users', 'send /users', 'send /users', 'wait /users', 'wait /users'],
            $this->log,
            'Once the probe lands, the queued requests go out together.',
        );
        $this->assertSame([], $locks->held);
        $this->assertSame(3, $locks->acquired, 'One probe plus two reservations.');
        $this->assertSame(3, $this->store->get($locks->keys[0])['remaining'] ?? null, 'The two reservations were spent from the revealed allowance.');
        $this->assertSame(0, $this->sleeper->count());
    }

    /**
     * @param callable(RequestInterface): PromiseInterface $fail
     */
    #[DataProvider('failedTransfers')]
    public function test_probe_lock_is_released_once_when_the_transfer_fails(callable $fail): void
    {
        $locks = new SpyLockFactory();

        $promise = $this->handler($locks)->handle(
            static fn (RequestInterface $request, array $options): PromiseInterface => $fail($request),
            new Request('GET', 'https://api.test/users'),
            [],
        );

        $this->assertSame(1, $locks->acquired);
        $this->assertSame(0, $locks->released, 'The probe keeps its lock while the request is in flight.');

        $failure = null;
        $releasedOnFailure = null;

        try {
            $promise->wait();
        } catch (Throwable $failure) {
            $releasedOnFailure = $locks->released;
        }

        $this->assertInstanceOf(ConnectException::class, $failure, 'The transfer failure rejects the returned promise.');
        $this->assertSame(1, $releasedOnFailure, 'The lock is back by the time the caller sees the failure.');

        Utils::queue()->run();

        $this->assertSame(1, $locks->released, 'Released exactly once.');
    }

    /**
     * @return iterable<string, array{callable(RequestInterface): PromiseInterface}>
     */
    public static function failedTransfers(): iterable
    {
        yield 'rejected once waited on' => [static function (RequestInterface $request): PromiseInterface {
            $promise = new Promise(static function () use (&$promise, $request): void {
                assert($promise instanceof Promise);
                $promise->reject(new ConnectException('Connection refused', $request));
            });

            return $promise;
        }];

        yield 'already rejected' => [static fn (RequestInterface $request): PromiseInterface => Create::rejectionFor(
            new ConnectException('Connection refused', $request),
        )];
    }

    public function test_cancelled_request_still_releases_the_probe_lock(): void
    {
        $locks = new SpyLockFactory();
        $handler = $this->handler($locks);
        $transport = $this->transport(
            new Response(200, ['X-RateLimit-Remaining' => '5']),
            new Response(200, ['X-RateLimit-Remaining' => '5']),
        );

        $handler->handle($transport, new Request('GET', 'https://api.test/users'), [])->cancel();
        Utils::queue()->run();

        $this->assertSame(1, $locks->released);

        // Nothing is left queued behind the abandoned probe.
        $next = $handler->handle($transport, new Request('GET', 'https://api.test/users'), []);
        $this->assertSame(['send /users', 'send /users'], $this->log);
        $this->assertSame(200, $next->wait()->getStatusCode());
    }

    public function test_429_is_retried_inside_the_promise_chain(): void
    {
        $client = $this->client($this->transport(
            new Response(429, ['Retry-After' => '2']),
            new Response(200, ['X-RateLimit-Remaining' => '9']),
        ));

        $promise = $client->getAsync('https://api.test/users');

        $this->assertSame(['send /users'], $this->log);
        $this->assertSame(0, $this->sleeper->count());

        $this->assertSame(200, $promise->wait()->getStatusCode());
        $this->assertSame(['send /users', 'wait /users', 'send /users', 'wait /users'], $this->log);
        $this->assertSame([3.0], $this->sleeper->sleeps); // 2 + 1 buffer
    }

    public function test_exhausted_retries_reject_with_rate_limit_exceeded(): void
    {
        $client = $this->client($this->transport(
            new Response(429, ['Retry-After' => '2']),
            new Response(429, ['Retry-After' => '2']),
        ), new Options(maxRetries: 1));

        $promise = $client->getAsync('https://api.test/users');

        $this->assertSame(['send /users'], $this->log);

        $this->expectException(RateLimitExceededException::class);

        $promise->wait();
    }

    public function test_failure_before_sending_rejects_instead_of_throwing(): void
    {
        $handler = $this->handler(options: new Options(invalidRequestLimit: new InvalidRequestLimit(maxRequests: 1)));
        $transport = $this->transport(new Response(401), new Response(200));
        $request = new Request('GET', 'https://api.test/users');

        $this->assertSame(401, $handler->handle($transport, $request, [])->wait()->getStatusCode());

        $promise = $handler->handle($transport, $request, []);

        $this->assertTrue(Is::rejected($promise));
        $this->assertSame(['send /users', 'wait /users'], $this->log, 'The spent budget stops the request before it is sent.');

        $this->expectException(InvalidRequestLimitExceededException::class);

        $promise->wait();
    }

    private function client(
        callable $transport,
        Options $options = new Options(),
        LockFactoryInterface $lockFactory = new NullLockFactory(),
    ): Client {
        $stack = HandlerStack::create($transport);
        $stack->push(RateLimitMiddleware::create(
            options: $options,
            store: $this->store,
            lockFactory: $lockFactory,
            clock: $this->clock,
            sleeper: $this->sleeper,
        ));

        return new Client(['handler' => $stack]);
    }

    private function handler(
        LockFactoryInterface $lockFactory = new NullLockFactory(),
        Options $options = new Options(),
    ): RateLimitHandler {
        return new RateLimitHandler(
            $options,
            new Headers(),
            $this->store,
            new NullLogger(),
            new DefaultBucketResolver(),
            $lockFactory,
            $this->clock,
            $this->sleeper,
        );
    }

    /**
     * An inner handler that answers with the given outcomes in order. Each
     * transfer completes only when something waits on it, and both events
     * are logged.
     */
    private function transport(ResponseInterface|Throwable ...$outcomes): callable
    {
        return function (RequestInterface $request, array $options) use (&$outcomes): PromiseInterface {
            $outcome = array_shift($outcomes);
            $this->assertNotNull($outcome, 'Unexpected extra request.');

            $path = $request->getUri()->getPath();
            $this->log[] = 'send ' . $path;

            $promise = new Promise(function () use (&$promise, $outcome, $path): void {
                assert($promise instanceof Promise);
                $this->log[] = 'wait ' . $path;

                $outcome instanceof Throwable ? $promise->reject($outcome) : $promise->resolve($outcome);
            });

            return $promise;
        };
    }
}
