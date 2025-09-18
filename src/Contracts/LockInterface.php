<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

interface LockInterface
{
    /**
     * Block until the lock is acquired or timeout is reached.
     * 
     * @param int $timeout Maximum time to wait in seconds
     * @return bool True if lock was acquired, false if timeout
     */
    public function block(int $timeout = PHP_INT_MAX): bool;

    /**
     * Release the lock.
     * 
     * @return bool True if lock was released successfully
     */
    public function release(): bool;

    /**
     * Check if the lock is currently held.
     * 
     * @return bool
     */
    public function isLocked(): bool;
}
