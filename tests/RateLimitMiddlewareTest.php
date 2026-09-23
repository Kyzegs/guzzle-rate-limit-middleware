<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Config\GlobalLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\RateLimitDelayExceededException;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Testing\FakeClock;
use Kyzegs\GuzzleRateLimitMiddleware\Testing\RecordingSleeper;
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
