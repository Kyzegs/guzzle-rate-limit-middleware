<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Config\GlobalLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\Config\InvalidRequestLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Limit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\StoreInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Event\RateLimitUpdated;
use Kyzegs\GuzzleRateLimitMiddleware\Event\RetryScheduled;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\DiscordBucketResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Retry\ExponentialBackoffRetryStrategy;
use Kyzegs\GuzzleRateLimitMiddleware\Retry\StatusCodeRetryStrategy;
use Kyzegs\GuzzleRateLimitMiddleware\Store\FilesystemStore;
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Store\RedisStore;
use Kyzegs\GuzzleRateLimitMiddleware\Support\DiscordRetryAfterResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Testing\FakeClock;
use Kyzegs\GuzzleRateLimitMiddleware\Testing\RecordingSleeper;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\ExclusiveLockFactory;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\RecordingEventDispatcher;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\RedisServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Every feature switched on at once, sent concurrently. Each feature has its own
 * tests; this one is for what only shows when they meet, such as a bookkeeping
 * step blocking on a lock its own process holds, which the lock double turns
 * into a failure.
 */
final class AllFeaturesTest extends TestCase
{
    private ?string $directory = null;

    protected function tearDown(): void
    {
        RedisServer::cleanUp();

        if ($this->directory !== null) {
            array_map('unlink', glob($this->directory . '/*') ?: []);
            rmdir($this->directory);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stores(): iterable
    {
        yield 'in-memory' => ['memory'];
        yield 'filesystem' => ['filesystem'];
        yield 'redis' => ['redis'];
    }

    #[DataProvider('stores')]
    public function test_every_feature_together(string $storeName): void
    {
        $clock = new FakeClock(1_800_000_000.0);
        $sleeper = new RecordingSleeper($clock);
        $locks = new ExclusiveLockFactory();
        $events = new RecordingEventDispatcher();

        $middleware = RateLimitMiddleware::create(
            options: new Options(
                safetyBufferSeconds: 0.0,
                globalLimit: GlobalLimit::discord(),
                invalidRequestLimit: new InvalidRequestLimit(),
                limits: [Limit::perSecond(100)],
            ),
            headers: Headers::discord(),
            store: $this->store($storeName, $clock),
            resolver: new DiscordBucketResolver($clock),
            lockFactory: $locks,
            clock: $clock,
            sleeper: $sleeper,
            retryAfterResolver: new DiscordRetryAfterResolver(),
            retryStrategy: new ExponentialBackoffRetryStrategy(new StatusCodeRetryStrategy(maxRetries: 2)),
            events: $events,
        );

        /** @var array<string, int> $sent */
        $sent = [];
        $transport =
            /**
             * @param array<mixed> $options
             * @return PromiseInterface<ResponseInterface, mixed>
             */
            function (RequestInterface $request, array $options) use (&$sent): PromiseInterface {
                $path = $request->getUri()->getPath();
                $sent[$path] = ($sent[$path] ?? 0) + 1;

                $this->assertArrayNotHasKey('rate_limit', $options, 'The option stops at the middleware.');

                if ($sent[$path] === 1 && str_ends_with($path, '/limited')) {
                    return Create::promiseFor(new Response(429, ['Retry-After' => '1'], '{"retry_after":1,"global":false}'));
                }

                return Create::promiseFor(new Response(200, [
                    'X-RateLimit-Limit' => '5',
                    'X-RateLimit-Remaining' => '4',
                    'X-RateLimit-Reset-After' => '30',
                    'X-RateLimit-Bucket' => 'bucket-' . md5($path),
                ]));
            };

        $client = $this->client($transport, $middleware);
        $api = 'https://discord.com/api/v10';

        $responses = Utils::all([
            $client->getAsync("{$api}/channels/1/limited"),
            $client->getAsync("{$api}/channels/1/limited"),
            $client->getAsync("{$api}/channels/2/messages", ['rate_limit' => ['identity' => 'tenant', 'max_retries' => 0]]),
            $client->postAsync("{$api}/interactions/9/token/callback"),
            $client->getAsync("{$api}/health", ['rate_limit' => ['enabled' => false]]),
        ])->wait();

        foreach ($responses as $response) {
            $this->assertSame(200, $response->getStatusCode());
        }

        $this->assertSame(3, $sent['/api/v10/channels/1/limited'], 'One 429, its retry, and the queued request.');
        $this->assertSame([], $locks->held, 'Every lock is given back.');
        $this->assertCount(1, $events->ofType(RetryScheduled::class));
        $this->assertNotSame([], $events->ofType(RateLimitUpdated::class));

        $request = new Request('GET', "{$api}/channels/1/limited", ['Authorization' => 'Bot token']);
        $this->assertSame(1, $middleware->invalidRequestBudget($request)?->count, 'The 429 counted.');
        $this->assertNotNull($middleware->state($request));
    }

    private function client(callable $transport, RateLimitMiddleware $middleware): Client
    {
        $stack = HandlerStack::create($transport);
        $stack->push($middleware);

        return new Client(['handler' => $stack, 'headers' => ['Authorization' => 'Bot token']]);
    }

    private function store(string $name, FakeClock $clock): StoreInterface
    {
        if ($name === 'redis') {
            $redis = RedisServer::predis();

            return new RedisStore($redis, RedisServer::prefix($redis));
        }

        if ($name === 'filesystem') {
            $this->directory = sys_get_temp_dir() . '/grl-all-' . bin2hex(random_bytes(6));

            return new FilesystemStore($this->directory, $clock);
        }

        return new InMemoryStore($clock);
    }
}
