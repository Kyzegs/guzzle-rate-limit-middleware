<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\LockHandlers;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockInterface;

/**
 * Null lock that doesn't actually perform any locking operations.
 */
class NullLock implements LockInterface
{
    public function block(int $timeout = PHP_INT_MAX): bool
    {
        return true; // Always "acquire" immediately
    }

    public function release(): bool
    {
        return true; // Always "release" successfully
    }

    public function isLocked(): bool
    {
        return false; // Never actually locked
    }
}
