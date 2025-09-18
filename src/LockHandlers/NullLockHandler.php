<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\LockHandlers;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockHandlerInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockInterface;

/**
 * Null lock handler that doesn't actually perform locking.
 * Useful for single-threaded applications or when locking isn't needed.
 */
class NullLockHandler implements LockHandlerInterface
{
    public function lock(string $key, int $timeout = 60): LockInterface
    {
        return new NullLock();
    }
}
