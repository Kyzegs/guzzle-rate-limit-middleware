<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Handler;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Config\GlobalLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockFactoryInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\SleeperInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Handler\RateLimitHandler;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\DefaultBucketResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Store\RedisStore;
use Kyzegs\GuzzleRateLimitMiddleware\Support\NullLockFactory;
use Kyzegs\GuzzleRateLimitMiddleware\Testing\FakeClock;
use Kyzegs\GuzzleRateLimitMiddleware\Testing\RecordingSleeper;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\RedisServer;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\SpyLockFactory;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\StoppedSleeping;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\StoppingSleeper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Redis;
use RuntimeException;

/**
 * The handler on a real RedisStore: buckets are reserved by the store's script,
 * with no bucket lock, and on the handler's (fake) clock.
 */
final class RateLimitHandlerRedisTest extends TestCase
{
    private FakeClock $clock;

    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->clock = new FakeClock(1_000.0);
        $this->sleeper = new RecordingSleeper($this->clock);
    }

    protected function tearDown(): void
    {
        RedisServer::cleanUp();
    }

    /**
     * @return array<string, array{callable(): (Redis|ClientInterface)}>
     */
    public static function clients(): array
    {
        return RedisServer::clients();
    }

    /**
     * @param callable(): (Redis|ClientInterface) $connect
     */
    #[DataProvider('clients')]
    public function test_reserved_slots_count_down_without_a_bucket_lock(callable $connect): void
    {
        $store = $this->store($connect);
        $lockFactory = new SpyLockFactory();
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
            $this->handler($store, lockFactory: $lockFactory)->handle($this->responder($response), $request, [])->wait();
        }

        $this->assertSame(0, $this->sleeper->count());

        $this->handler($store, lockFactory: $lockFactory)->handle($this->responder($response), $request, [])->wait();

        $this->assertSame(1, $this->sleeper->count(), 'Spent reservations must not be handed back.');
        $this->assertEqualsWithDelta(11.0, $this->sleeper->sleeps[0], 0.001);
        $this->assertSame([], $lockFactory->keys, 'No bucket lock is taken with an atomic store.');
    }

    /**
     * @param callable(): (Redis|ClientInterface) $connect
     */
    #[DataProvider('clients')]
    public function test_other_callers_wait_for_an_in_flight_probe(callable $connect): void
    {
        $store = $this->store($connect);
        $request = new Request('GET', 'https://api.test/users');
        $response = new Response(200, ['X-RateLimit-Remaining' => '5', 'X-RateLimit-Reset' => '10']);

        $waiter = new StoppingSleeper();
        $waiterSent = false;
        $this->handler($store)->handle(
            function () use ($store, $waiter, $request, $response, &$waiterSent): ResponseInterface {
                // A second caller arrives while the probe is in flight.
                try {
                    $this->handler($store, sleeper: $waiter)->handle(
                        static function () use ($response, &$waiterSent): ResponseInterface {
                            $waiterSent = true;

                            return $response;
                        },
                        $request,
                        [],
                    );
                } catch (StoppedSleeping) {
                }

                return $response;
            },
            $request,
            [],
        )->wait();

        $this->assertFalse($waiterSent, 'Only the probe may go while the allowance is unknown.');
        $this->assertCount(1, $waiter->sleeps);
        $this->assertLessThanOrEqual(0.05, $waiter->sleeps[0], 'Polling a probe adds no safety margin.');

        // The probe's response opened the bucket.
        $this->handler($store)->handle($this->responder($response), $request, [])->wait();
        $this->assertSame(0, $this->sleeper->count());
    }

    /**
     * @param callable(): (Redis|ClientInterface) $connect
     */
    #[DataProvider('clients')]
    public function test_a_failed_probe_lets_the_next_caller_probe_at_once(callable $connect): void
    {
        $store = $this->store($connect);
        $request = new Request('GET', 'https://api.test/users');

        try {
            $this->handler($store)->handle(
                static fn (): ResponseInterface => throw new RuntimeException('connection reset'),
                $request,
                [],
            )->wait();
        } catch (RuntimeException) {
        }

        $sleeper = new StoppingSleeper();
        $response = $this->handler($store, sleeper: $sleeper)->handle(
            $this->responder(new Response(200, ['X-RateLimit-Remaining' => '5', 'X-RateLimit-Reset' => '10'])),
            $request,
            [],
        )->wait();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $sleeper->sleeps, 'The failed probe must have released its marker.');
    }

    /**
     * @param callable(): (Redis|ClientInterface) $connect
     */
    #[DataProvider('clients')]
    public function test_the_global_budget_still_goes_through_the_lock_factory(callable $connect): void
    {
        $store = $this->store($connect);
        $lockFactory = new SpyLockFactory();
        $handler = $this->handler(
            $store,
            new Options(safetyBufferSeconds: 0, globalLimit: new GlobalLimit(maxRequests: 2, windowSeconds: 1)),
            $lockFactory,
        );
        $respond = $this->responder(new Response(200, ['X-RateLimit-Remaining' => '10', 'X-RateLimit-Reset' => '10']));

        $handler->handle($respond, new Request('GET', 'https://api.test/one', ['Authorization' => 'Bot secret']), [])->wait();
        $handler->handle($respond, new Request('GET', 'https://api.test/two', ['Authorization' => 'Bot secret']), [])->wait();
        $handler->handle($respond, new Request('GET', 'https://api.test/three', ['Authorization' => 'Bot secret']), [])->wait();

        $this->assertSame(1, $this->sleeper->count());
        $this->assertEqualsWithDelta(1.0, $this->sleeper->sleeps[0], 0.001);
        $this->assertNotEmpty($lockFactory->keys);
        foreach ($lockFactory->keys as $key) {
            $this->assertStringStartsWith('global:', $key);
        }
    }

    /**
     * @param callable(): (Redis|ClientInterface) $connect
     */
    private function store(callable $connect): RedisStore
    {
        $redis = $connect();

        return new RedisStore($redis, RedisServer::prefix($redis));
    }

    private function handler(
        RedisStore $store,
        Options $options = new Options(),
        ?LockFactoryInterface $lockFactory = null,
        ?SleeperInterface $sleeper = null,
    ): RateLimitHandler {
        return new RateLimitHandler(
            $options,
            new Headers(),
            $store,
            new NullLogger(),
            new DefaultBucketResolver(),
            $lockFactory ?? new NullLockFactory(),
            $this->clock,
            $sleeper ?? $this->sleeper,
        );
    }

    private function responder(ResponseInterface $response): callable
    {
        return static fn (): ResponseInterface => $response;
    }
}
