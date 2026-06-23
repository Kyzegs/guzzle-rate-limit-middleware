<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests;

use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\FakeClock;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    public function test_parses_remaining_and_limit(): void
    {
        $clock = new FakeClock(1_000.0);
        $response = new Response(200, [
            'X-RateLimit-Limit' => '100',
            'X-RateLimit-Remaining' => '42',
        ]);

        $rateLimit = RateLimit::fromResponse($response, new Headers(), $clock);

        $this->assertSame(100, $rateLimit->limit);
        $this->assertSame(42, $rateLimit->remaining);
    }

    public function test_reset_after_is_treated_as_relative(): void
    {
        $clock = new FakeClock(1_000.0);
        $response = new Response(200, ['X-RateLimit-Reset-After' => '30']);

        $rateLimit = RateLimit::fromResponse($response, Headers::discord(), $clock);

        $this->assertSame(30.0, $rateLimit->secondsUntilReset($clock));
    }

    public function test_absolute_reset_timestamp_is_respected(): void
    {
        $clock = new FakeClock(1_000.0);
        $response = new Response(200, ['X-RateLimit-Reset' => (string) (1_000_000_000)]);

        $rateLimit = RateLimit::fromResponse($response, new Headers(), $clock);

        $this->assertSame(1_000_000_000.0 - 1_000.0, $rateLimit->secondsUntilReset($clock));
    }

    public function test_small_reset_value_is_treated_as_relative(): void
    {
        $clock = new FakeClock(1_000.0);
        $response = new Response(200, ['X-RateLimit-Reset' => '15']);

        $rateLimit = RateLimit::fromResponse($response, new Headers(), $clock);

        $this->assertSame(15.0, $rateLimit->secondsUntilReset($clock));
    }

    public function test_retry_after_used_when_no_reset_header(): void
    {
        $clock = new FakeClock(1_000.0);
        $response = new Response(429, ['Retry-After' => '7']);

        $rateLimit = RateLimit::fromResponse($response, new Headers(), $clock);

        $this->assertSame(7.0, $rateLimit->secondsUntilReset($clock));
    }

    public function test_should_delay_when_exhausted_and_not_yet_reset(): void
    {
        $clock = new FakeClock(1_000.0);
        $response = new Response(200, [
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset-After' => '5',
        ]);

        $rateLimit = RateLimit::fromResponse($response, Headers::discord(), $clock);

        $this->assertTrue($rateLimit->shouldDelay($clock));

        $clock->advance(6);
        $this->assertFalse($rateLimit->shouldDelay($clock));
    }

    public function test_does_not_delay_when_requests_remain(): void
    {
        $clock = new FakeClock(1_000.0);
        $response = new Response(200, [
            'X-RateLimit-Remaining' => '3',
            'X-RateLimit-Reset-After' => '5',
        ]);

        $rateLimit = RateLimit::fromResponse($response, Headers::discord(), $clock);

        $this->assertFalse($rateLimit->shouldDelay($clock));
    }

    public function test_array_round_trip(): void
    {
        $rateLimit = new RateLimit(limit: 10, remaining: 2, reset: 1234.5, bucketHash: 'abc');

        $restored = RateLimit::fromArray($rateLimit->toArray());

        $this->assertEquals($rateLimit, $restored);
    }
}
