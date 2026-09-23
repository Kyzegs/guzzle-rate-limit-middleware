<?php

declare(strict_types=1);

/**
 * Worker process for CrossProcessLockTest: sends one request through a
 * middleware that shares its FilesystemStore and FlockLockFactory with the
 * other workers, and reports whether the request went out ("sent") or would
 * have had to wait for the window to reset ("waited").
 *
 * A sent request's response is held back until the parent writes a line, so
 * every worker has claimed its slot, or been turned away, before any response
 * lands, as in a burst against a slow API.
 *
 * Usage: php burst_worker.php <store dir> <lock dir> <url> <reset timestamp>
 */

require __DIR__ . '/../../vendor/autoload.php';

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\RateLimitDelayExceededException;
use Kyzegs\GuzzleRateLimitMiddleware\Lock\FlockLockFactory;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;
use Kyzegs\GuzzleRateLimitMiddleware\Store\FilesystemStore;

[, $storeDirectory, $lockDirectory, $url, $reset] = $argv;

$api = new MockHandler([
    static function () use ($reset): Response {
        fwrite(STDOUT, "sent\n");
        fgets(STDIN);

        return new Response(200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => $reset]);
    },
]);

$send = HandlerStack::create($api);
$send->push(RateLimitMiddleware::create(
    // The window is far off, so waiting for it would exceed this and throw:
    // a cheap way to tell "had to wait" apart without sleeping.
    options: new Options(maxDelaySeconds: 1.0),
    store: new FilesystemStore($storeDirectory),
    lockFactory: new FlockLockFactory($lockDirectory),
));

// Wait at the start line so the workers overlap instead of running in turn.
fwrite(STDOUT, "ready\n");
fgets(STDIN);

// The stack is invoked directly rather than through a Client: the middleware
// runs synchronously either way, and Client's docblocks promise nothing but
// GuzzleException, which would make static analysis flag the catch below.
try {
    $send(new Request('GET', $url), []);
} catch (RateLimitDelayExceededException) {
    fwrite(STDOUT, "waited\n");
}
