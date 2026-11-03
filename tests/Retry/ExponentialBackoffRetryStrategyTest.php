<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Retry;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Retry\ExponentialBackoffRetryStrategy;
use Kyzegs\GuzzleRateLimitMiddleware\Retry\StatusCodeRetryStrategy;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ExponentialBackoffRetryStrategyTest extends TestCase
{
    public function test_doubles_the_wait_when_nothing_was_hinted(): void
    {
        $strategy = new ExponentialBackoffRetryStrategy(
            new StatusCodeRetryStrategy(maxRetries: 4),
            baseSeconds: 0.5,
            maxSeconds: 60.0,
            random: static fn (): float => 1.0,
        );

        $this->assertSame([0.5, 1.0, 2.0, 4.0], $this->delays($strategy, 4));
    }

    public function test_caps_the_wait_at_max_seconds(): void
    {
        $strategy = new ExponentialBackoffRetryStrategy(
            new StatusCodeRetryStrategy(maxRetries: 6),
            baseSeconds: 1.0,
            maxSeconds: 5.0,
            random: static fn (): float => 1.0,
        );

        $this->assertSame([1.0, 2.0, 4.0, 5.0, 5.0, 5.0], $this->delays($strategy, 6));
    }

    public function test_full_jitter_scales_the_wait_by_the_random_draw(): void
    {
        $draws = [0.25, 0.5, 0.0];
        $strategy = new ExponentialBackoffRetryStrategy(
            new StatusCodeRetryStrategy(maxRetries: 3),
            baseSeconds: 2.0,
            maxSeconds: 60.0,
            random: static function () use (&$draws): float {
                $draw = array_shift($draws);
                TestCase::assertNotNull($draw);

                return $draw;
            },
        );

        // Ceilings of 2s, 4s and 8s, each scaled by its draw.
        $this->assertSame([0.5, 2.0, 0.0], $this->delays($strategy, 3));
    }

    public function test_leaves_a_hinted_wait_alone(): void
    {
        $strategy = new ExponentialBackoffRetryStrategy(
            new StatusCodeRetryStrategy(),
            random: static fn (): float => throw new LogicException('No backoff expected.'),
        );

        $delay = $strategy->retryDelay(new Request('GET', 'https://api.test/users'), new Response(429), 2, 7.0);

        $this->assertSame(7.0, $delay);
    }

    public function test_does_not_retry_when_the_inner_strategy_declines(): void
    {
        $strategy = new ExponentialBackoffRetryStrategy(new StatusCodeRetryStrategy(maxRetries: 1));
        $request = new Request('GET', 'https://api.test/users');

        $this->assertNull($strategy->retryDelay($request, new Response(500), 1, 0.0));
        $this->assertNull($strategy->retryDelay($request, new Response(429), 2, 0.0));
    }

    public function test_default_jitter_stays_within_the_ceiling(): void
    {
        $strategy = new ExponentialBackoffRetryStrategy(new StatusCodeRetryStrategy(), baseSeconds: 3.0);

        $delay = $strategy->retryDelay(new Request('GET', 'https://api.test/users'), new Response(429), 1, 0.0);

        $this->assertNotNull($delay);
        $this->assertGreaterThanOrEqual(0.0, $delay);
        $this->assertLessThanOrEqual(3.0, $delay);
    }

    public function test_rate_limit_classification_comes_from_the_inner_strategy(): void
    {
        $strategy = new ExponentialBackoffRetryStrategy(new StatusCodeRetryStrategy(statusCodes: [503]));

        $this->assertTrue($strategy->isRateLimited(new Response(429)));
        $this->assertFalse($strategy->isRateLimited(new Response(503)));
    }

    /**
     * @return list<float|null>
     */
    private function delays(ExponentialBackoffRetryStrategy $strategy, int $attempts): array
    {
        $request = new Request('GET', 'https://api.test/users');
        $response = new Response(429);

        $delays = [];
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $delays[] = $strategy->retryDelay($request, $response, $attempt, 0.0);
        }

        return $delays;
    }
}
