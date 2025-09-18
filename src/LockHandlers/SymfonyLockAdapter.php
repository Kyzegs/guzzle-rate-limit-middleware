<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\LockHandlers;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockInterface;
use Symfony\Component\Lock\LockInterface as SymfonyLockInterface;

/**
 * Adapter to bridge Symfony's Lock to our LockInterface.
 */
class SymfonyLockAdapter implements LockInterface
{
    public function __construct(
        private readonly SymfonyLockInterface $lock
    ) {}

    public function block(int $timeout = PHP_INT_MAX): bool
    {
        if ($timeout === PHP_INT_MAX) {
            // Symfony doesn't support infinite timeout, use a very large number
            $timeout = 86400; // 24 hours
        }
        
        return $this->lock->acquire(true, $timeout);
    }

    public function release(): bool
    {
        $this->lock->release();

        return true; // Symfony's release() is void, assume success
    }

    public function isLocked(): bool
    {
        return $this->lock->isAcquired();
    }
}
