<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

interface LockFactoryInterface
{
    /**
     * Create a lock scoped to the given key. Requests sharing a key are serialised.
     */
    public function make(string $key): LockInterface;
}
