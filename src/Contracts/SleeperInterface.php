<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

interface SleeperInterface
{
    /**
     * Block the current process for the given number of seconds.
     */
    public function sleep(float $seconds): void;
}
