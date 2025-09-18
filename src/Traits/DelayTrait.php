<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\Traits;

trait DelayTrait
{
    /**
     * Delay execution by the specified number of seconds.
     */
    protected function delay(float $seconds): void
    {
        usleep((int) ($seconds * 1000000)); // Convert to microseconds
    }
}
