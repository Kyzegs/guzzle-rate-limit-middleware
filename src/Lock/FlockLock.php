<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Lock;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\LockTimeoutException;
use RuntimeException;
use Throwable;

/**
 * An exclusive flock() on one file. Obtain it from {@see FlockLockFactory}.
 *
 * The lock belongs to the open file handle: holding it means keeping the
 * handle open, and releasing it means closing the handle. That also makes it
 * per instance, so two instances for the same key exclude each other even
 * within one process.
 */
final class FlockLock implements LockInterface
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(
        private readonly string $key,
        private readonly string $path,
        private readonly ?float $timeoutSeconds = null,
        private readonly float $pollIntervalSeconds = 0.01,
    ) {
    }

    /**
     * @throws LockTimeoutException when a timeout is set and the lock stays taken past it
     */
    public function acquire(): void
    {
        if ($this->handle !== null) {
            return;
        }

        // "c" creates the file when missing without truncating it, and opens
        // it for writing, which some platforms require for an exclusive lock.
        $handle = @fopen($this->path, 'c');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open lock file "%s".', $this->path));
        }

        try {
            if ($this->timeoutSeconds === null) {
                $this->lockBlocking($handle);
            } else {
                $this->lockWithin($handle, $this->timeoutSeconds);
            }
        } catch (Throwable $e) {
            fclose($handle);

            throw $e;
        }

        $this->handle = $handle;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        $handle = $this->handle;
        $this->handle = null;

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * @param resource $handle
     */
    private function lockBlocking($handle): void
    {
        if (! flock($handle, LOCK_EX)) {
            throw new RuntimeException(sprintf('Unable to lock "%s".', $this->path));
        }
    }

    /**
     * flock() has no timeout of its own, so bound the wait by repeating a
     * non-blocking attempt until the deadline. Any failed attempt counts as
     * "still taken": the would-block flag is not reported on every platform.
     *
     * @param resource $handle
     */
    private function lockWithin($handle, float $timeout): void
    {
        $deadline = self::now() + $timeout;

        while (! flock($handle, LOCK_EX | LOCK_NB)) {
            $remaining = $deadline - self::now();

            if ($remaining <= 0.0) {
                throw new LockTimeoutException($this->key, $timeout);
            }

            usleep((int) (min($this->pollIntervalSeconds, $remaining) * 1_000_000));
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
