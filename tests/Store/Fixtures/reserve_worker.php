<?php

declare(strict_types=1);

/**
 * One competing process for RedisStoreConcurrencyTest.
 *
 * Usage: reserve_worker.php <phpredis|predis> <host> <port> <prefix> <key> <now> <drain|once>
 *
 * Connects, prints "ready", waits for a line on stdin so that every worker
 * starts at the same moment, then either takes slots until the bucket says
 * wait ("drain": prints how many it got) or reserves once ("once": prints the
 * outcome).
 */

use Kyzegs\GuzzleRateLimitMiddleware\Reservation;
use Kyzegs\GuzzleRateLimitMiddleware\Store\RedisStore;
use Predis\Client;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = array_slice($_SERVER['argv'] ?? [], 1);

if (count($arguments) !== 7) {
    fwrite(STDERR, "Usage: reserve_worker.php <phpredis|predis> <host> <port> <prefix> <key> <now> <drain|once>\n");
    exit(2);
}

[$client, $host, $port, $prefix, $key, $now, $mode] = $arguments;

if ($client === 'phpredis') {
    $redis = new Redis();
    $redis->connect($host, (int) $port, 1.0);
} else {
    $redis = new Client(['host' => $host, 'port' => (int) $port, 'timeout' => 1.0]);
}

$store = new RedisStore($redis, $prefix);

fwrite(STDOUT, "ready\n");
fgets(STDIN);

$describe = static fn (Reservation $reservation): string => match (true) {
    $reservation->isReserved() => 'reserved',
    $reservation->isProbe() => 'probe',
    $reservation->probeInFlight => 'pending',
    default => 'wait',
};

if ($mode === 'once') {
    fwrite(STDOUT, $describe($store->reserve($key, (float) $now)) . "\n");
    exit(0);
}

$reserved = 0;

while (($reservation = $store->reserve($key, (float) $now))->isReserved()) {
    $reserved++;
}

if ($describe($reservation) !== 'wait') {
    fwrite(STDERR, 'Unexpected outcome: ' . $describe($reservation) . "\n");
    exit(1);
}

fwrite(STDOUT, $reserved . "\n");
