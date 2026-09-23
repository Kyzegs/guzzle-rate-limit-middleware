<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\Parser\FractionHeaderParser;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\HostBucketResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\FakeClock;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\RecordingSleeper;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\RecordingStore;
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

    public function test_create_passes_the_parser_through(): void
    {
        $clock = new FakeClock(1_000.0);
        $sleeper = new RecordingSleeper($clock);

        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['X-Api-Call-Limit' => '40/40']),
            new Response(200, ['X-Api-Call-Limit' => '39/40']),
        ]));
        $stack->push(RateLimitMiddleware::create(
            store: new InMemoryStore($clock),
            clock: $clock,
            sleeper: $sleeper,
            parser: new FractionHeaderParser('X-Api-Call-Limit'),
        ));
        $client = new Client(['handler' => $stack]);

        $client->get('https://api.test/users');
        $client->get('https://api.test/users');

        $this->assertSame(1, $sleeper->count());
        $this->assertEqualsWithDelta(1.5, $sleeper->sleeps[0], 0.001); // one leak at 2/s + 1 buffer
    }

    public function test_ietf_preset_reads_structured_fields(): void
    {
        $store = new RecordingStore();

        $this->client(RateLimitMiddleware::ietf(store: $store), new Response(200, [
            'RateLimit-Policy' => '"burst";q=10;w=1, "daily";q=1000;w=86400',
            'RateLimit' => '"burst";r=9;t=1, "daily";r=990;t=40000',
        ]))->get('https://api.test/users');

        $stored = $store->lastPut();
        $this->assertSame(10, $stored['limit']);
        $this->assertSame(9, $stored['remaining']);
        $this->assertEqualsWithDelta(microtime(true) + 1, $stored['reset'], 5.0);
    }

    public function test_ietf_preset_still_reads_legacy_headers(): void
    {
        $store = new RecordingStore();

        $this->client(RateLimitMiddleware::ietf(store: $store), new Response(200, [
            'RateLimit-Limit' => '100',
            'RateLimit-Remaining' => '42',
            'RateLimit-Reset' => '30',
        ]))->get('https://api.test/users');

        $stored = $store->lastPut();
        $this->assertSame(100, $stored['limit']);
        $this->assertSame(42, $stored['remaining']);
    }

    public function test_shopify_preset_tracks_one_bucket_per_shop(): void
    {
        $store = new RecordingStore();

        $client = $this->client(
            RateLimitMiddleware::shopify(store: $store),
            new Response(200, ['X-Shopify-Shop-Api-Call-Limit' => '32/40']),
            new Response(200, ['X-Shopify-Shop-Api-Call-Limit' => '33/40']),
        );
        $client->get('https://shop.myshopify.com/admin/api/2024-10/products.json');
        $client->post('https://shop.myshopify.com/admin/api/2024-10/orders.json');

        $keys = array_unique(array_column($store->puts, 'key'));
        $this->assertCount(1, $keys, 'Every endpoint drains the same bucket.');

        $stored = $store->lastPut();
        $this->assertSame(40, $stored['limit']);
        $this->assertSame(7, $stored['remaining']);
    }

    public function test_shopify_style_429_waits_for_a_fractional_retry_after(): void
    {
        $clock = new FakeClock(1_000.0);
        $sleeper = new RecordingSleeper($clock);

        // Mirrors the shopify() preset, which cannot take a clock or sleeper.
        $stack = HandlerStack::create(new MockHandler([
            new Response(429, ['Retry-After' => '2.0', 'X-Shopify-Shop-Api-Call-Limit' => '40/40']),
            new Response(200, ['X-Shopify-Shop-Api-Call-Limit' => '36/40']),
        ]));
        $stack->push(RateLimitMiddleware::create(
            store: new InMemoryStore($clock),
            resolver: new HostBucketResolver(),
            clock: $clock,
            sleeper: $sleeper,
            parser: new FractionHeaderParser('X-Shopify-Shop-Api-Call-Limit'),
        ));
        $client = new Client(['handler' => $stack]);

        $response = $client->get('https://shop.myshopify.com/admin/api/2024-10/products.json');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([3.0], $sleeper->sleeps); // 2.0 + 1 buffer; the full bucket leaked meanwhile
    }

    private function client(RateLimitMiddleware $middleware, Response ...$responses): Client
    {
        $stack = HandlerStack::create(new MockHandler(array_values($responses)));
        $stack->push($middleware);

        return new Client(['handler' => $stack]);
    }
}
