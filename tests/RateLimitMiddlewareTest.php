<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Config\GlobalLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Limit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\LimitAlgorithm;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\RateLimitDelayExceededException;
use Kyzegs\GuzzleRateLimitMiddleware\Parser\FractionHeaderParser;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\HostBucketResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Retry\GitHubRetryStrategy;
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Testing\FakeClock;
use Kyzegs\GuzzleRateLimitMiddleware\Testing\RecordingSleeper;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\RecordingStore;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\SpyLockFactory;
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

    public function test_create_retries_by_the_given_strategy(): void
    {
        $clock = new FakeClock(1_700_000_000.0);
        $sleeper = new RecordingSleeper($clock);

        $mock = new MockHandler([
            new Response(403, ['Retry-After' => '5']),
            new Response(200, ['X-RateLimit-Remaining' => '4999', 'X-RateLimit-Reset' => '1700003600']),
        ]);

        $stack = HandlerStack::create($mock);
        $stack->push(RateLimitMiddleware::create(
            headers: Headers::github(),
            store: new InMemoryStore($clock),
            clock: $clock,
            sleeper: $sleeper,
            retryStrategy: new GitHubRetryStrategy(),
        ));

        $client = new Client(['handler' => $stack]);

        $this->assertSame(200, $client->get('https://api.github.com/user')->getStatusCode());
        $this->assertSame([6.0], $sleeper->sleeps); // 5s Retry-After + 1 buffer
    }

    public function test_client_side_limits_pace_an_api_that_sends_no_headers(): void
    {
        $clock = new FakeClock(1_000.0);
        $sleeper = new RecordingSleeper($clock);

        $stack = HandlerStack::create(new MockHandler(array_fill(0, 4, new Response(200))));
        $stack->push(RateLimitMiddleware::create(
            options: new Options(safetyBufferSeconds: 0, limits: [
                Limit::perSecond(2, LimitAlgorithm::FixedWindow),
                Limit::perMinute(3, LimitAlgorithm::FixedWindow),
            ]),
            store: new InMemoryStore($clock),
            clock: $clock,
            sleeper: $sleeper,
        ));

        $client = new Client(['handler' => $stack, 'headers' => ['Authorization' => 'Bearer token']]);

        for ($i = 0; $i < 4; $i++) {
            $client->get('https://api.test/users');
        }

        // The third request waits for the next second, the fourth for the next minute.
        $this->assertCount(2, $sleeper->sleeps);
        $this->assertEqualsWithDelta(1.0, $sleeper->sleeps[0], 0.001);
        $this->assertEqualsWithDelta(59.0, $sleeper->sleeps[1], 0.001);
    }

    public function test_passes_through_successful_responses(): void
    {
        $mock = new MockHandler([new Response(204)]);
        $stack = HandlerStack::create($mock);
        $stack->push(RateLimitMiddleware::github());

        $client = new Client(['handler' => $stack]);

        $this->assertSame(204, $client->get('https://api.github.com/user')->getStatusCode());
    }

    public function test_discord_preset_exempts_interaction_callbacks_from_its_default_global_budget(): void
    {
        $locks = new SpyLockFactory();
        $client = $this->client(RateLimitMiddleware::discord(lockFactory: $locks, clock: new FakeClock()), 2);

        $client->post('https://discord.com/api/v10/interactions/1/tok/callback');
        $this->assertSame([], self::globalLockKeys($locks));

        $client->get('https://discord.com/api/v10/users/@me');
        $this->assertCount(1, self::globalLockKeys($locks), 'Other routes still spend the global budget.');
    }

    public function test_discord_preset_exempts_interaction_callbacks_from_a_custom_global_limit(): void
    {
        $client = $this->client(RateLimitMiddleware::discord(
            options: new Options(
                globalLimit: new GlobalLimit(maxRequests: 1, windowSeconds: 60),
                maxDelaySeconds: 5,
            ),
            clock: new FakeClock(),
        ), 5);

        for ($i = 0; $i < 3; $i++) {
            $client->post('https://discord.com/api/v10/interactions/1/tok/callback');
        }

        $client->get('https://discord.com/api/v10/users/@me');

        // The one slot is spent, and waiting out the 60s window exceeds maxDelaySeconds.
        $this->expectException(RateLimitDelayExceededException::class);

        $client->get('https://discord.com/api/v10/users/@me');
    }

    public function test_discord_preset_dates_messages_by_its_own_clock(): void
    {
        // A message from 2023: old by the real clock, one hour old by the fake one.
        $createdAt = 1_700_000_000.0;
        $messageId = ((int) ($createdAt * 1000) - 1_420_070_400_000) << 22;
        $clock = new FakeClock($createdAt + 3_600);
        $locks = new SpyLockFactory();
        $client = $this->client(RateLimitMiddleware::discord(lockFactory: $locks, clock: $clock), 2);
        $url = "https://discord.com/api/v10/channels/1/messages/{$messageId}";

        $client->delete($url);
        $clock->advance(14 * 86_400);
        $client->delete($url);

        $bucketKeys = array_values(array_diff($locks->keys, self::globalLockKeys($locks)));
        $this->assertCount(2, $bucketKeys);
        $this->assertNotSame($bucketKeys[0], $bucketKeys[1], 'The message only turns old on the injected clock.');
    }

    private function client(RateLimitMiddleware $middleware, int $responses): Client
    {
        $stack = HandlerStack::create(new MockHandler(array_fill(0, $responses, new Response(204))));
        $stack->push($middleware);

        return new Client(['handler' => $stack]);
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

        $this->clientAnswering(RateLimitMiddleware::ietf(store: $store), new Response(200, [
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

        $this->clientAnswering(RateLimitMiddleware::ietf(store: $store), new Response(200, [
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

        $client = $this->clientAnswering(
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

    private function clientAnswering(RateLimitMiddleware $middleware, Response ...$responses): Client
    {
        $stack = HandlerStack::create(new MockHandler(array_values($responses)));
        $stack->push($middleware);

        return new Client(['handler' => $stack]);
    }

    /**
     * @return list<string>
     */
    private static function globalLockKeys(SpyLockFactory $locks): array
    {
        return array_values(array_filter(
            $locks->keys,
            static fn (string $key): bool => str_starts_with($key, 'global:'),
        ));
    }
}
