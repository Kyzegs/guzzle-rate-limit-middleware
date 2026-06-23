<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

interface ClockInterface
{
    /**
     * The current time as a UNIX timestamp with microsecond precision.
     */
    public function now(): float;
}
