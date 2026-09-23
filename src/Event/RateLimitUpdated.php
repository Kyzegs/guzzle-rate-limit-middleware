<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Event;

use Kyzegs\GuzzleRateLimitMiddleware\RateLimit;
use Psr\Http\Message\RequestInterface;

/**
 * Dispatched after the bucket state from a response has been stored: once per
 * response, including responses without rate-limit headers.
 *
 * `$rateLimit` is the state as persisted, not as the response reported it:
 * when an out-of-order response claims more allowance than has already been
 * reserved in the same window, the lower count wins, and that is what a gauge
 * of remaining capacity should show. `$key` is the opaque storage key it was
 * written under, after any bucket-hash re-keying.
 */
final class RateLimitUpdated
{
    public function __construct(
        public readonly RequestInterface $request,
        public readonly RateLimit $rateLimit,
        public readonly string $key,
    ) {
    }
}
