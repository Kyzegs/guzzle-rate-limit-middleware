<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\ClockInterface;

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
