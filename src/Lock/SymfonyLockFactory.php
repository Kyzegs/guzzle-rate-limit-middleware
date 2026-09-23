<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Lock;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockFactoryInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Adapter over symfony/lock, for coordinating workers on several hosts through
 * a shared store such as Redis or a database.
 *
 * Unlike flock(), a lock in a remote store outlives a worker that crashes
 * while holding it, so every lock carries a TTL after which it expires on its
 * own. The TTL has to cover the longest time the middleware keeps a lock.
 * Usually that is only the bookkeeping around a request, but while a bucket's
 * allowance is unknown one request holds the lock across its whole HTTP round
 * trip, so keep the TTL above your Guzzle `timeout`. If it lapses mid-request,
 * a second caller may probe the same bucket and draw a 429.
 */
final class SymfonyLockFactory implements LockFactoryInterface
{
    /**
     * @param float      $ttlSeconds     How long a held lock survives a crashed holder. Must exceed the longest request.
     * @param float|null $timeoutSeconds How long acquire() may wait before throwing a LockTimeoutException; null waits indefinitely.
     */
    public function __construct(
        private readonly LockFactory $factory,
        private readonly float $ttlSeconds = 30.0,
        private readonly ?float $timeoutSeconds = null,
    ) {
    }

    public function make(string $key): LockInterface
    {
        // Auto-release frees the lock if the instance is dropped while held,
        // instead of leaving it to the TTL.
        return new SymfonyLock(
            $this->factory->createLock($key, $this->ttlSeconds, autoRelease: true),
            $key,
            $this->timeoutSeconds,
        );
    }
}
