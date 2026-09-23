<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Event;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Dispatched just before the middleware sleeps to resend a request whose
 * response carried a retryable status (a 429 by default).
 *
 * A retry means the pre-emptive delays were not enough — another process
 * spent the bucket, or the API tightened it — so this is the event to count
 * and alert on. `$attempt` is 1-based and never exceeds `$maxRetries`;
 * `$delay` is exactly what is about to be slept.
 */
final class RetryScheduled
{
    public function __construct(
        public readonly RequestInterface $request,
        public readonly ResponseInterface $response,
        public readonly int $attempt,
        public readonly int $maxRetries,
        public readonly float $delay,
        public readonly bool $global,
    ) {
    }
}
