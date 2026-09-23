<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Event;

use Psr\Http\Message\RequestInterface;

/**
 * Dispatched just before the middleware sleeps because a bucket is spent or
 * the global request budget is used up: the pre-emptive wait that keeps a 429
 * from happening at all.
 *
 * `$delay` is exactly what is about to be slept, safety buffer and jitter
 * included, so summing it gives the time requests spent waiting. `$key` is the
 * opaque storage key (a hash), never the raw route or credential, so it is
 * safe to log. Like every event here it is a read-only notification: a
 * listener cannot shorten or skip the wait.
 */
final class RequestDelayed
{
    public function __construct(
        public readonly RequestInterface $request,
        public readonly float $delay,
        public readonly DelayReason $reason,
        public readonly string $key,
    ) {
    }
}
