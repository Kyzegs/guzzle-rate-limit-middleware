<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\LockHandlers;

use Illuminate\Support\Facades\Cache;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockHandlerInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockInterface;

/**
 * Laravel-compatible lock handler that uses Laravel's Cache locks.
 */
class LaravelLockHandler implements LockHandlerInterface
{
    public function lock(string $key, int $timeout = 60): LockInterface
    {
        $lock = Cache::lock($key, $timeout);

        return new LaravelLockAdapter($lock);
    }
}
