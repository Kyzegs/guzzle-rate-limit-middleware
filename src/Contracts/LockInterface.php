<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

interface LockInterface
{
    /**
     * Acquire the lock, blocking until it becomes available.
     */
    public function acquire(): void;

    /**
     * Release the lock. Safe to call even if the lock was never acquired.
     */
    public function release(): void;
}
