<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\Event\RateLimitUpdated;
use Kyzegs\GuzzleRateLimitMiddleware\Event\RetryScheduled;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\FakeClock;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\RecordingEventDispatcher;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\RecordingSleeper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

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

    public function test_forwards_events_to_the_default_handler(): void
    {
        $clock = new FakeClock(1_000.0);
        $events = new RecordingEventDispatcher();

        $mock = new MockHandler([
            new Response(429, ['Retry-After' => '2']),
            new Response(200, ['X-RateLimit-Remaining' => '9', 'X-RateLimit-Reset' => '20']),
        ]);

        $stack = HandlerStack::create($mock);
        $stack->push(RateLimitMiddleware::create(
            store: new InMemoryStore($clock),
            clock: $clock,
            sleeper: new RecordingSleeper($clock),
            events: $events,
        ));

        (new Client(['handler' => $stack]))->get('https://api.test/users');

        $this->assertSame(
            [RateLimitUpdated::class, RetryScheduled::class, RateLimitUpdated::class],
            array_map(get_class(...), $events->events),
        );
    }

    /**
     * @param Closure(EventDispatcherInterface): RateLimitMiddleware $preset
     */
    #[DataProvider('presets')]
    public function test_presets_accept_an_event_dispatcher(Closure $preset): void
    {
        $events = new RecordingEventDispatcher();

        $stack = HandlerStack::create(new MockHandler([new Response(200)]));
        $stack->push($preset($events));

        (new Client(['handler' => $stack]))->get('https://api.test/users');

        $this->assertCount(1, $events->ofType(RateLimitUpdated::class));
    }

    /**
     * @return iterable<string, array{Closure(EventDispatcherInterface): RateLimitMiddleware}>
     */
    public static function presets(): iterable
    {
        yield 'discord' => [static fn (EventDispatcherInterface $events) => RateLimitMiddleware::discord(events: $events)];
        yield 'github' => [static fn (EventDispatcherInterface $events) => RateLimitMiddleware::github(events: $events)];
        yield 'twitter' => [static fn (EventDispatcherInterface $events) => RateLimitMiddleware::twitter(events: $events)];
        yield 'ietf' => [static fn (EventDispatcherInterface $events) => RateLimitMiddleware::ietf(events: $events)];
    }
}
