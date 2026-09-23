<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Lock;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockFactoryInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\LockTimeoutException;
use Kyzegs\GuzzleRateLimitMiddleware\Support\RedisConnection;
use Predis\ClientInterface;
use Redis;

/**
 * Cross-process locks on Redis, through phpredis or Predis.
 *
 * With RedisStore, buckets need no lock at all; this covers what still goes
 * through the lock factory, the global and invalid-request budgets. With any
 * other shared store it covers buckets too.
 *
 * Each lock is a key under the prefix plus a SHA-1 of the lock's name, holding
 * a random token until it is released or its TTL runs out.
 */
final class RedisLockFactory implements LockFactoryInterface
{
    private readonly RedisConnection $redis;

    /**
     * @param float      $ttlSeconds     How long a lock outlives a holder that never releases it
     *                                   (a crashed process). With a non-atomic store it is held
     *                                   across a probe's round trip, so keep it above your HTTP timeout.
     * @param float|null $timeoutSeconds How long acquire() waits before throwing
     *                                   {@see LockTimeoutException}; null waits indefinitely.
     */
    public function __construct(
        Redis|ClientInterface $redis,
        private readonly float $ttlSeconds = 30.0,
        private readonly ?float $timeoutSeconds = null,
        private readonly string $prefix = 'grl:lock:',
    ) {
        $this->redis = new RedisConnection($redis);
    }

    public function make(string $key): LockInterface
    {
        return new RedisLock($this->redis, $key, $this->prefix . sha1($key), $this->ttlSeconds, $this->timeoutSeconds);
    }
}
