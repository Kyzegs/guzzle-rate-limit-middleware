<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\LockHandlers;

use Illuminate\Contracts\Cache\Lock;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockInterface;

/**
 * Adapter to bridge Laravel's Lock to our LockInterface.
 */
class LaravelLockAdapter implements LockInterface
{
    public function __construct(
        private readonly Lock $lock
    ) {}

    public function block(int $timeout = PHP_INT_MAX): bool
    {
        return $this->lock->block($timeout);
    }

    public function release(): bool
    {
        return $this->lock->release();
    }

    public function isLocked(): bool
    {
        // Laravel's Lock doesn't have an isLocked method, 
        // so we'll try to acquire and immediately release if successful
        $acquired = $this->lock->get();
        if ($acquired) {
            $this->lock->release();
            return false;
        }
        return true;
    }
}
