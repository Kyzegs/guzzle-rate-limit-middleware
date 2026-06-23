<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Support;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockInterface;

/**
 * A lock that does nothing. Used when no concurrency control is configured.
 */
final class NullLock implements LockInterface
{
    public function acquire(): void
    {
        //
    }

    public function release(): void
    {
        //
    }
}
