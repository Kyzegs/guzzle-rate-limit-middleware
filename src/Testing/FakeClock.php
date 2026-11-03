<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Testing;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\ClockInterface;

/**
 * A clock that only moves when told to, for asserting rate-limit timing in
 * your own tests. Pair it with {@see RecordingSleeper}, which advances it
 * instead of blocking.
 */
final class FakeClock implements ClockInterface
{
    public function __construct(private float $now = 1_000_000.0)
    {
    }

    public function now(): float
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }

    public function set(float $now): void
    {
        $this->now = $now;
    }
}
