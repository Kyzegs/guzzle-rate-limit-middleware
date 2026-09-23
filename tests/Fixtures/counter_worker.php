<?php

declare(strict_types=1);

/**
 * Worker process for CrossProcessLockTest: increments a shared counter file,
 * doing each read-modify-write under a FlockLock. Lost updates mean the lock
 * let two processes in at once.
 *
 * Usage: php counter_worker.php <lock dir> <counter file> <increments>
 */

require __DIR__ . '/../../vendor/autoload.php';

use Kyzegs\GuzzleRateLimitMiddleware\Lock\FlockLockFactory;

[, $lockDirectory, $counterFile, $increments] = $_SERVER['argv'];

$locks = new FlockLockFactory($lockDirectory);

// Wait at the start line so the workers overlap instead of running in turn.
fwrite(STDOUT, "ready\n");
fgets(STDIN);

for ($i = 0; $i < (int) $increments; $i++) {
    $lock = $locks->make('counter');
    $lock->acquire();

    try {
        $count = (int) file_get_contents($counterFile);
        file_put_contents($counterFile, (string) ($count + 1));
    } finally {
        $lock->release();
    }
}

fwrite(STDOUT, "done\n");
