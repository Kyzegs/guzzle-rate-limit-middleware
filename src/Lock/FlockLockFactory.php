<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Lock;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockFactoryInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockInterface;
use RuntimeException;

/**
 * Cross-process locks for a single host, built on flock(). The natural partner
 * of {@see \Kyzegs\GuzzleRateLimitMiddleware\Store\FilesystemStore}: no extra
 * dependencies, and the kernel drops a lock the moment its holder exits, so a
 * crashed worker can never leave a bucket locked.
 *
 * Each key gets its own lock file, named by the key's hash so any key is a
 * safe file name. The files are left in place after release: deleting one
 * while another process waits on it would let a third process lock a fresh
 * file under the same name, and both would believe they hold the lock. There
 * is one empty file per bucket, so the directory stays small.
 *
 * flock() only coordinates processes on the same host, and is unreliable on
 * network filesystems such as NFS. To coordinate several hosts use
 * {@see SymfonyLockFactory} with a Redis or database store.
 */
final class FlockLockFactory implements LockFactoryInterface
{
    private readonly string $directory;

    /**
     * @param string|null $directory           Where the lock files live. Defaults to a folder in the system temp dir.
     * @param float|null  $timeoutSeconds      How long acquire() may wait before throwing a LockTimeoutException; null waits indefinitely.
     * @param float       $pollIntervalSeconds How often a waiting acquire() retries while a timeout is set.
     */
    public function __construct(
        ?string $directory = null,
        private readonly ?float $timeoutSeconds = null,
        private readonly float $pollIntervalSeconds = 0.01,
    ) {
        $this->directory = $directory ?? sys_get_temp_dir() . '/guzzle-rate-limit-locks';

        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0775, true) && ! is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Unable to create lock directory "%s".', $this->directory));
        }
    }

    public function make(string $key): LockInterface
    {
        return new FlockLock(
            $key,
            $this->directory . '/' . sha1($key) . '.lock',
            $this->timeoutSeconds,
            $this->pollIntervalSeconds,
        );
    }
}
