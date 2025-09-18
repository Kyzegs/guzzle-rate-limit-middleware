<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\LockHandlers;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockHandlerInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Symfony-compatible lock handler that uses Symfony's Lock component.
 */
class SymfonyLockHandler implements LockHandlerInterface
{
    public function __construct(
        private readonly LockFactory $lockFactory
    ) {}

    public function lock(string $key, int $timeout = 60): LockInterface
    {
        $lock = $this->lockFactory->createLock($key, $timeout);

        return new SymfonyLockAdapter($lock);
    }
}
