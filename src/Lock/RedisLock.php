<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Lock;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\LockTimeoutException;
use Kyzegs\GuzzleRateLimitMiddleware\Support\RedisConnection;

/**
 * One Redis lock: `SET key token NX PX ttl`, released by deleting the key only
 * while it still holds this holder's token.
 *
 * The token is what makes release safe. A holder that outlives the TTL loses
 * the key to expiry and someone else may take it; a plain DEL from the old
 * holder would then free the new holder's lock as well.
 *
 * @internal Made by {@see RedisLockFactory}, and by RedisStore for a bucket's
 *           probe marker, which is the same kind of lock taken inside its
 *           reservation script and handed over already held.
 */
final class RedisLock implements LockInterface
{
    private const RELEASE_SCRIPT = <<<'LUA'
        if redis.call('GET', KEYS[1]) == ARGV[1] then
            return redis.call('DEL', KEYS[1])
        end
        return 0
        LUA;

    /**
     * @param string      $name  The key the lock was asked for, for the timeout exception.
     * @param string      $key   The Redis key that holds it.
     * @param string|null $token Set when the lock was already taken on this holder's behalf.
     */
    public function __construct(
        private readonly RedisConnection $redis,
        private readonly string $name,
        private readonly string $key,
        private readonly float $ttlSeconds,
        private readonly ?float $timeoutSeconds = null,
        private ?string $token = null,
    ) {
    }

    public function acquire(): void
    {
        if ($this->token !== null) {
            return;
        }

        $token = bin2hex(random_bytes(16));
        $ttl = max(1, (int) ceil($this->ttlSeconds * 1000));
        $timeout = $this->timeoutSeconds;
        $deadline = $timeout === null ? null : microtime(true) + $timeout;

        while (! $this->redis->setIfAbsent($this->key, $token, $ttl)) {
            // A short, jittered pause: long enough not to hammer Redis, short
            // enough that a released lock is picked up almost at once, and
            // random so that waiters don't retry in lockstep.
            $pause = random_int(5_000, 25_000);

            if ($timeout !== null && $deadline !== null) {
                $left = $deadline - microtime(true);

                if ($left <= 0.0) {
                    throw new LockTimeoutException($this->name, $timeout);
                }

                $pause = min($pause, (int) ceil($left * 1_000_000));
            }

            usleep($pause);
        }

        $this->token = $token;
    }

    public function release(): void
    {
        if ($this->token === null) {
            return;
        }

        $token = $this->token;
        $this->token = null;

        $this->redis->evaluate(self::RELEASE_SCRIPT, [$this->key], [$token]);
    }
}
