<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Support;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockFactoryInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockInterface;

final class NullLockFactory implements LockFactoryInterface
{
    public function make(string $key): LockInterface
    {
        return new NullLock();
    }
}
