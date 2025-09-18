<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

interface LockHandlerInterface
{
    /**
     * Acquire a lock for the given key.
     * 
     * @param string $key
     * @param int $timeout Maximum time to wait for lock in seconds
     * @return LockInterface
     */
    public function lock(string $key, int $timeout = 60): LockInterface;
}
