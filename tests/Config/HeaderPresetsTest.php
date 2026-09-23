<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Config;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\Handler\RateLimitHandler;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\DefaultBucketResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Support\NullLockFactory;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\FakeClock;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\RecordingSleeper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

final class HeaderPresetsTest extends TestCase
{
    private const NOW = 1_767_225_600.0;

    /**
     * Each API's headers as documented, for an exhausted window that resets
     * 30 seconds from now.
     *
     * @return iterable<string, array{Headers, array<string, string>}>
     */
    public static function exhaustedResponses(): iterable
    {
        $reset = (string) (int) (self::NOW + 30);

        yield 'GitLab' => [Headers::gitlab(), [
            'RateLimit-Limit' => '600',
            'RateLimit-Remaining' => '0',
            'RateLimit-Reset' => $reset,
        ]];
        yield 'Twitch' => [Headers::twitch(), [
            'Ratelimit-Limit' => '800',
            'Ratelimit-Remaining' => '0',
            'Ratelimit-Reset' => $reset,
        ]];
        yield 'Reddit' => [Headers::reddit(), [
            'X-Ratelimit-Used' => '100',
            'X-Ratelimit-Remaining' => '0.0',
            'X-Ratelimit-Reset' => '30',
        ]];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('exhaustedResponses')]
    public function test_preset_reads_an_exhausted_window(Headers $preset, array $headers): void
    {
        $clock = new FakeClock(self::NOW);

        $rateLimit = RateLimit::fromResponse(new Response(200, $headers), $preset, $clock);

        $this->assertSame(0, $rateLimit->remaining);
        $this->assertEqualsWithDelta(30.0, $rateLimit->secondsUntilReset($clock), 0.001);
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('exhaustedResponses')]
    public function test_preset_waits_for_the_window_to_reset(Headers $preset, array $headers): void
    {
        $clock = new FakeClock(self::NOW);
        $sleeper = new RecordingSleeper($clock);
        $handler = new RateLimitHandler(
            new Options(safetyBufferSeconds: 0),
            $preset,
            new InMemoryStore($clock),
            new NullLogger(),
            new DefaultBucketResolver(),
            new NullLockFactory(),
            $clock,
            $sleeper,
        );
        $respond = static fn (): ResponseInterface => new Response(200, $headers);
        $request = new Request('GET', 'https://api.test/items');

        $handler->handle($respond, $request, []);
        $handler->handle($respond, $request, []);

        $this->assertCount(1, $sleeper->sleeps);
        $this->assertEqualsWithDelta(30.0, $sleeper->sleeps[0], 0.001);
    }

    public function test_reddit_decimal_remaining_is_read(): void
    {
        $rateLimit = RateLimit::fromResponse(
            new Response(200, ['X-Ratelimit-Remaining' => '598.0', 'X-Ratelimit-Reset' => '240']),
            Headers::reddit(),
            new FakeClock(self::NOW),
        );

        $this->assertSame(598, $rateLimit->remaining);
    }
}
