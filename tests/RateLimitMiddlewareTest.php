<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\RateLimitDelayExceededException;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\FakeClock;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\RecordingSleeper;
use PHPUnit\Framework\TestCase;

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

    public function test_rate_limit_request_option_is_honoured_and_not_forwarded(): void
    {
        $clock = new FakeClock(1_000.0);
        $sleeper = new RecordingSleeper($clock);
        $mock = new MockHandler([
            new Response(200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '20']),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(RateLimitMiddleware::create(store: new InMemoryStore($clock), clock: $clock, sleeper: $sleeper));
        $client = new Client(['handler' => $stack]);

        $client->get('https://api.test/users', ['rate_limit' => ['bucket' => 'shared']]);
        $this->assertArrayNotHasKey('rate_limit', $mock->getLastOptions());

        $this->expectException(RateLimitDelayExceededException::class);
        $client->post('https://api.test/orders', ['rate_limit' => ['bucket' => 'shared', 'max_delay' => 0]]);
    }

    public function test_rate_limit_option_can_be_set_for_a_whole_client(): void
    {
        $clock = new FakeClock(1_000.0);
        $sleeper = new RecordingSleeper($clock);
        $exhausted = new Response(200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '20']);
        $stack = HandlerStack::create(new MockHandler([$exhausted, $exhausted]));
        $stack->push(RateLimitMiddleware::create(store: new InMemoryStore($clock), clock: $clock, sleeper: $sleeper));
        $client = new Client(['handler' => $stack, 'rate_limit' => ['enabled' => false]]);

        $client->get('https://api.test/users');
        $client->get('https://api.test/users');

        $this->assertSame(0, $sleeper->count());
    }
}
