<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;
use Kyzegs\GuzzleRateLimitMiddleware\Store\FilesystemStore;

/**
 * Rate-limit state is persisted to disk, so a second, completely separate run
 * of this script (a different process) will honour the limit recorded by the
 * first run and sleep before sending its request.
 *
 * Swap FilesystemStore for Psr16Store(new RedisAdapter(...)) to share state
 * across hosts.
 */
$store = new FilesystemStore(sys_get_temp_dir() . '/grl-example');

$mock = new MockHandler([
    // Exhausted bucket that resets 5 seconds from now.
    new Response(200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) (time() + 5)]),
]);

$stack = HandlerStack::create($mock);
$stack->push(RateLimitMiddleware::github(store: $store));

$client = new Client(['handler' => $stack]);

$start = microtime(true);
$client->get('https://api.github.com/user');
echo sprintf("First run took %.1fs (no wait).\n", microtime(true) - $start);
echo "Run this script again within 5s: it will sleep until the bucket resets.\n";
