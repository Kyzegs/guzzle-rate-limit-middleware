<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Support;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Support\Psr20Clock;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\RecordingSleeper;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class Psr20ClockTest extends TestCase
{
    public function test_converts_to_a_float_timestamp_with_microseconds(): void
    {
        $clock = new Psr20Clock($this->frozen('2026-01-01 00:00:00.250000 UTC'));

        $this->assertSame(1767225600.25, $clock->now());
    }

    public function test_middleware_accepts_a_psr_20_clock(): void
    {
        $psrClock = $this->frozen('@1767225600');
        $sleeper = new RecordingSleeper();
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '1767225620']),
            new Response(200, ['X-RateLimit-Remaining' => '9']),
        ]));
        $stack->push(RateLimitMiddleware::create(
            store: new InMemoryStore(new Psr20Clock($psrClock)),
            clock: $psrClock,
            sleeper: $sleeper,
        ));
        $client = new Client(['handler' => $stack]);

        $client->get('https://api.test/users');
        $client->get('https://api.test/users');

        // 20s until the reset the PSR-20 clock puts us before, + 1s buffer.
        $this->assertSame([21.0], $sleeper->sleeps);
    }

    private function frozen(string $time): ClockInterface
    {
        return new class(new DateTimeImmutable($time)) implements ClockInterface {
            public function __construct(private readonly DateTimeImmutable $now)
            {
            }

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
    }
}
