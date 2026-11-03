<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Support;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\ClockInterface;
use Psr\Clock\ClockInterface as PsrClockInterface;

/**
 * Adapts any PSR-20 clock (symfony/clock, lcobucci/clock, a framework's test
 * clock, ...) to the float timestamps the rate limiter does its arithmetic
 * in, so the application and the middleware can share one notion of "now".
 */
final class Psr20Clock implements ClockInterface
{
    public function __construct(private readonly PsrClockInterface $clock)
    {
    }

    public function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
