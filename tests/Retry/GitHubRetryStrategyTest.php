<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Retry;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Retry\GitHubRetryStrategy;
use PHPUnit\Framework\TestCase;

final class GitHubRetryStrategyTest extends TestCase
{
    private const SECONDARY_LIMIT_BODY = '{"message":"You have exceeded a secondary rate limit. Please wait a few minutes before you try again."}';

    private const PERMISSION_BODY = '{"message":"Resource not accessible by integration"}';

    public function test_primary_limit_waits_for_the_hinted_reset(): void
    {
        $response = new Response(403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '1700000030']);

        $this->assertTrue($this->strategy()->isRateLimited($response));
        $this->assertSame(30.0, $this->delay($this->strategy(), $response, 1, 30.0));
    }

    public function test_primary_limit_on_a_429_waits_for_the_hinted_reset(): void
    {
        $response = new Response(429, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '1700000030']);

        $this->assertSame(30.0, $this->delay($this->strategy(), $response, 1, 30.0));
    }

    public function test_secondary_limit_with_retry_after_waits_that_long(): void
    {
        $response = new Response(403, ['Retry-After' => '45', 'X-RateLimit-Remaining' => '4000'], self::SECONDARY_LIMIT_BODY);

        $this->assertTrue($this->strategy()->isRateLimited($response));
        $this->assertSame(45.0, $this->delay($this->strategy(), $response, 1, 45.0));
    }

    public function test_secondary_limit_without_headers_waits_a_minute_then_doubles(): void
    {
        // The reset belongs to the primary window, most of an hour away; it
        // must not stand in for the secondary limit's wait.
        $response = new Response(403, ['X-RateLimit-Remaining' => '4000', 'X-RateLimit-Reset' => '1700003000'], self::SECONDARY_LIMIT_BODY);
        $strategy = new GitHubRetryStrategy(maxRetries: 3);

        $this->assertTrue($strategy->isRateLimited($response));
        $this->assertSame(60.0, $this->delay($strategy, $response, 1, 3000.0));
        $this->assertSame(120.0, $this->delay($strategy, $response, 2, 3000.0));
        $this->assertSame(240.0, $this->delay($strategy, $response, 3, 3000.0));
    }

    public function test_bare_429_is_treated_as_a_secondary_limit(): void
    {
        $response = new Response(429);

        $this->assertTrue($this->strategy()->isRateLimited($response));
        $this->assertSame(60.0, $this->delay($this->strategy(), $response, 1, 0.0));
    }

    public function test_plain_403_is_not_retried(): void
    {
        $response = new Response(403, ['X-RateLimit-Remaining' => '4999', 'X-RateLimit-Reset' => '1700003000'], self::PERMISSION_BODY);

        $this->assertFalse($this->strategy()->isRateLimited($response));
        $this->assertNull($this->delay($this->strategy(), $response, 1, 3000.0));
    }

    public function test_other_statuses_are_not_retried(): void
    {
        $exhaustedSuccess = new Response(200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '1700000030']);

        $this->assertFalse($this->strategy()->isRateLimited($exhaustedSuccess));
        $this->assertNull($this->delay($this->strategy(), $exhaustedSuccess, 1, 30.0));
        $this->assertNull($this->delay($this->strategy(), new Response(500, ['Retry-After' => '5']), 1, 5.0));
    }

    public function test_stops_once_max_retries_are_used_but_still_reports_the_rate_limit(): void
    {
        $strategy = new GitHubRetryStrategy(maxRetries: 2);
        $response = new Response(403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '1700000030']);

        $this->assertSame(10.0, $this->delay($strategy, $response, 2, 10.0));
        $this->assertNull($this->delay($strategy, $response, 3, 10.0));
        $this->assertTrue($strategy->isRateLimited($response));
    }

    public function test_reading_the_message_leaves_the_body_where_it_was(): void
    {
        $response = new Response(403, [], self::PERMISSION_BODY);
        $response->getBody()->read(4);

        $this->strategy()->isRateLimited($response);

        $this->assertSame(4, $response->getBody()->tell());
        $this->assertSame(self::PERMISSION_BODY, (string) $response->getBody());
    }

    private function strategy(): GitHubRetryStrategy
    {
        return new GitHubRetryStrategy();
    }

    private function delay(GitHubRetryStrategy $strategy, Response $response, int $attempt, float $hintedDelay): ?float
    {
        return $strategy->retryDelay(new Request('GET', 'https://api.github.com/repos/o/r'), $response, $attempt, $hintedDelay);
    }
}
