<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Retry;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\Retry\StatusCodeRetryStrategy;
use PHPUnit\Framework\TestCase;

final class StatusCodeRetryStrategyTest extends TestCase
{
    public function test_retries_a_configured_status_after_the_hinted_delay(): void
    {
        $strategy = new StatusCodeRetryStrategy(maxRetries: 3, statusCodes: [429, 503]);
        $request = new Request('GET', 'https://api.test/users');

        $this->assertSame(4.0, $strategy->retryDelay($request, new Response(429), 1, 4.0));
        $this->assertSame(0.0, $strategy->retryDelay($request, new Response(503), 1, 0.0));
    }

    public function test_does_not_retry_other_statuses(): void
    {
        $strategy = new StatusCodeRetryStrategy();
        $request = new Request('GET', 'https://api.test/users');

        $this->assertNull($strategy->retryDelay($request, new Response(200), 1, 5.0));
        $this->assertNull($strategy->retryDelay($request, new Response(503), 1, 5.0));
    }

    public function test_stops_once_max_retries_are_used(): void
    {
        $strategy = new StatusCodeRetryStrategy(maxRetries: 2);
        $request = new Request('GET', 'https://api.test/users');
        $response = new Response(429);

        $this->assertSame(1.0, $strategy->retryDelay($request, $response, 2, 1.0));
        $this->assertNull($strategy->retryDelay($request, $response, 3, 1.0));
    }

    public function test_built_from_options(): void
    {
        $strategy = StatusCodeRetryStrategy::fromOptions(new Options(maxRetries: 1, retryStatusCodes: [503]));
        $request = new Request('GET', 'https://api.test/users');

        $this->assertSame(2.0, $strategy->retryDelay($request, new Response(503), 1, 2.0));
        $this->assertNull($strategy->retryDelay($request, new Response(503), 2, 2.0));
        $this->assertNull($strategy->retryDelay($request, new Response(429), 1, 2.0));
    }

    public function test_only_a_429_is_rate_limited(): void
    {
        $strategy = new StatusCodeRetryStrategy(statusCodes: [429, 503]);

        $this->assertTrue($strategy->isRateLimited(new Response(429)));
        $this->assertFalse($strategy->isRateLimited(new Response(503)));
        $this->assertFalse($strategy->isRateLimited(new Response(403)));
    }
}
