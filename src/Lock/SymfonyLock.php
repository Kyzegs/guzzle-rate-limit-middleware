<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Lock;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\LockTimeoutException;
use Symfony\Component\Lock\LockInterface as SymfonyLockInterface;

/**
 * A symfony/lock lock behind the middleware's lock contract. Obtain it from
 * {@see SymfonyLockFactory}.
 */
final class SymfonyLock implements LockInterface
{
    private const FIRST_POLL_SECONDS = 0.005;

    private const MAX_POLL_SECONDS = 0.1;

    private bool $held = false;

    public function __construct(
        private readonly SymfonyLockInterface $lock,
        private readonly string $key,
        private readonly ?float $timeoutSeconds = null,
    ) {
    }

    /**
     * @throws LockTimeoutException when a timeout is set and the lock stays taken past it
     */
    public function acquire(): void
    {
        if ($this->held) {
            return;
        }

        if ($this->timeoutSeconds === null) {
            $this->lock->acquire(true);
        } else {
            $this->acquireWithin($this->timeoutSeconds);
        }

        $this->held = true;
    }

    /**
     * Only a lock this instance holds is released, so a second call, or one
     * without an acquire, costs no round trip to the store.
     */
    public function release(): void
    {
        if (! $this->held) {
            return;
        }

        $this->held = false;
        $this->lock->release();
    }

    /**
     * Symfony's blocking acquire has no timeout, so poll a non-blocking one
     * until the deadline. Every attempt is a round trip to the store, hence
     * the backoff: it starts short because the lock is usually held only for
     * bookkeeping, and levels off at Symfony's own 100ms retry interval.
     */
    private function acquireWithin(float $timeout): void
    {
        $deadline = self::now() + $timeout;
        $interval = self::FIRST_POLL_SECONDS;

        while (! $this->lock->acquire(false)) {
            $remaining = $deadline - self::now();

            if ($remaining <= 0.0) {
                throw new LockTimeoutException($this->key, $timeout);
            }

            usleep((int) (min($interval, $remaining) * 1_000_000));
            $interval = min($interval * 2, self::MAX_POLL_SECONDS);
        }
    }

    /**
     * Monotonic, so a wall-clock adjustment cannot stretch or cut the timeout.
     */
    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
