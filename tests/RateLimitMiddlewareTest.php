<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\HandlerInterface;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Support\SystemClock;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\FakeClock;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\RecordingSleeper;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class RateLimitMiddlewareTest extends TestCase
{
    public function test_end_to_end_delays_second_request_when_exhausted(): void
    {
        $clock = new FakeClock(1_000.0);
        $sleeper = new RecordingSleeper($clock);
        $store = new InMemoryStore($clock);

        $mock = new MockHandler([
            new Response(200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '20']),
            new Response(200, ['X-RateLimit-Remaining' => '9', 'X-RateLimit-Reset' => '20']),
        ]);

        $stack = HandlerStack::create($mock);
        $stack->push(RateLimitMiddleware::create(
            store: $store,
            clock: $clock,
            sleeper: $sleeper,
        ));

        $client = new Client(['handler' => $stack]);

        $client->get('https://api.test/users');
        $this->assertSame(0, $sleeper->count());

        $client->get('https://api.test/users');
        $this->assertSame(1, $sleeper->count());
        $this->assertEqualsWithDelta(21.0, $sleeper->sleeps[0], 0.001); // 20 + 1 buffer
    }

    public function test_passes_through_successful_responses(): void
    {
        $mock = new MockHandler([new Response(204)]);
        $stack = HandlerStack::create($mock);
        $stack->push(RateLimitMiddleware::github());

        $client = new Client(['handler' => $stack]);

        $this->assertSame(204, $client->get('https://api.github.com/user')->getStatusCode());
    }

    public function test_state_and_forget_reach_the_bucket_the_client_uses(): void
    {
        $clock = new FakeClock(1_000.0);
        $sleeper = new RecordingSleeper($clock);
        $middleware = RateLimitMiddleware::create(
            store: new InMemoryStore($clock),
            clock: $clock,
            sleeper: $sleeper,
        );

        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['X-RateLimit-Limit' => '10', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '20']),
            new Response(200, ['X-RateLimit-Limit' => '10', 'X-RateLimit-Remaining' => '9', 'X-RateLimit-Reset' => '20']),
        ]));
        $stack->push($middleware);
        $client = new Client(['handler' => $stack, 'base_uri' => 'https://api.test', 'headers' => ['Authorization' => 'Bearer token']]);

        // Build the request as the client sends it: absolute URI, same credentials.
        $request = new Request('GET', 'https://api.test/users', ['Authorization' => 'Bearer token']);
        $this->assertNull($middleware->state($request));

        $client->get('/users');

        $state = $middleware->state($request);
        $this->assertNotNull($state);
        $this->assertSame(0, $state->remaining);
        $this->assertTrue($state->shouldDelay($clock));

        $middleware->forget($request);
        $client->get('/users');

        $this->assertSame(0, $sleeper->count());
        $this->assertSame(9, $middleware->state($request)?->remaining);
    }

    public function test_discord_preset_state_follows_the_discovered_bucket(): void
    {
        $middleware = RateLimitMiddleware::discord();
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [
                'X-RateLimit-Limit' => '5',
                'X-RateLimit-Remaining' => '4',
                'X-RateLimit-Reset-After' => '60',
                'X-RateLimit-Bucket' => 'abcd',
            ]),
        ]));
        $stack->push($middleware);
        $client = new Client(['handler' => $stack]);
        $auth = ['Authorization' => 'Bot secret'];

        $client->post('https://discord.com/api/v10/channels/123/messages', ['headers' => $auth]);

        $state = $middleware->state(new Request('POST', 'https://discord.com/api/v10/channels/123/messages', $auth));
        $this->assertNotNull($state);
        $this->assertSame('abcd', $state->bucketHash);
        $this->assertSame(4, $state->remaining);
        $this->assertGreaterThan(0.0, $state->secondsUntilReset(new SystemClock()));

        $this->assertNull($middleware->state(new Request('POST', 'https://discord.com/api/v10/channels/456/messages', $auth)));

        // The preset's self-imposed budgets are exposed too (the real clock
        // makes the one-second global window too short to assert a count on).
        $me = new Request('GET', 'https://discord.com/api/v10/users/@me', $auth);
        $this->assertSame(50, $middleware->globalBudget($me)?->max);
        $this->assertSame(9_000, $middleware->invalidRequestBudget($me)?->remaining());
    }

    #[DataProvider('inspectionMethods')]
    public function test_inspection_requires_an_inspectable_handler(string $method): void
    {
        $middleware = new RateLimitMiddleware(handler: new class implements HandlerInterface {
            public function handle(callable $handler, RequestInterface $request, array $options): mixed
            {
                return $handler($request, $options);
            }
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Cannot call {$method}()");

        $middleware->{$method}(new Request('GET', 'https://api.test/users'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function inspectionMethods(): iterable
    {
        yield 'state' => ['state'];
        yield 'forget' => ['forget'];
        yield 'globalBudget' => ['globalBudget'];
        yield 'invalidRequestBudget' => ['invalidRequestBudget'];
    }
}
