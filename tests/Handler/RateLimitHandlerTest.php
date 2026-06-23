<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Handler;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\BucketResolverInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockFactoryInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\StoreInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\RateLimitExceededException;
use Kyzegs\GuzzleRateLimitMiddleware\Handler\RateLimitHandler;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\DefaultBucketResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\DiscordBucketResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Support\NullLockFactory;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\FakeClock;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\RecordingSleeper;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\SpyLockFactory;
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
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $this->sleeper->count());
    }

    public function test_sleeps_until_reset_when_bucket_exhausted(): void
    {
        $store = $this->store();
        $request = new Request('GET', 'https://api.test/users');
        $exhausted = new Response(200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '10']);

        // First call records the exhausted state.
        $this->handler($store)->handle($this->responder($exhausted), $request, []);
        $this->assertSame(0, $this->sleeper->count());

        // Second call (fresh handler, same store) must wait for the reset window.
        $this->handler($store)->handle($this->responder($exhausted), $request, []);

        $this->assertSame(1, $this->sleeper->count());
        // 10s until reset + 1s default safety buffer.
        $this->assertEqualsWithDelta(11.0, $this->sleeper->sleeps[0], 0.001);
    }

    public function test_state_persists_across_independent_handler_instances(): void
    {
        $store = $this->store();
        $request = new Request('GET', 'https://api.test/widgets');
        $exhausted = new Response(200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '30']);

        $this->handler($store)->handle($this->responder($exhausted), $request, []);

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

        $secondHandler->handle($this->responder($exhausted), $request, []);

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
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $this->sleeper->count());
        $this->assertEqualsWithDelta(5.0, $this->sleeper->sleeps[0], 0.001); // 4 + 1 buffer
    }

    public function test_throws_after_retries_exhausted(): void
    {
        $handler = $this->handler($this->store(), new Options(maxRetries: 1));

        $this->expectException(RateLimitExceededException::class);

        $handler->handle(
            $this->responder(new Response(429, ['Retry-After' => '2'])),
            new Request('GET', 'https://api.test/users'),
            [],
        );
    }

    public function test_does_not_throw_when_disabled(): void
    {
        $handler = $this->handler($this->store(), new Options(maxRetries: 1, throwOnRateLimit: false));

        $response = $handler->handle(
            $this->responder(new Response(429, ['Retry-After' => '2'])),
            new Request('GET', 'https://api.test/users'),
            [],
        );

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
        );

        $this->assertNotNull(
            $store->get('bucket:abcd:channel_id=123'),
            'State should be stored under the discovered bucket key.',
        );
    }

    public function test_lock_is_acquired_and_released(): void
    {
        $lockFactory = new SpyLockFactory();
        $handler = $this->handler($this->store(), new Options(), new Headers(), new DefaultBucketResolver(), $lockFactory);

        $handler->handle(
            $this->responder(new Response(200, ['X-RateLimit-Remaining' => '5'])),
            new Request('GET', 'https://api.test/users'),
            [],
        );

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
            );
        } catch (RateLimitExceededException) {
            // expected
        }

        $this->assertSame(1, $lockFactory->released);
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
