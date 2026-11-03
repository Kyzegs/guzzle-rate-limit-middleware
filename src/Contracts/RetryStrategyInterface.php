<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Decides whether a response is worth retrying, how long to wait first, and
 * whether it is a rate limit at all.
 *
 * APIs disagree on what a rate limit looks like (GitHub answers with a 403 as
 * readily as a 429) and on how long to back off when they don't say, so this
 * is the seam for that knowledge. The handler still owns the sleeping: it
 * adds the safety buffer and jitter from Options to whatever is returned, and
 * enforces Options::$maxDelaySeconds.
 */
interface RetryStrategyInterface
{
    /**
     * Seconds to wait before retrying, or null to stop and hand the response
     * back.
     *
     * @param int   $attempt     1-based number of the retry about to happen.
     * @param float $hintedDelay What the RetryAfterResolver read off the response
     *                           (Retry-After, else the bucket reset); 0.0 when it
     *                           found nothing or the response is not an error.
     */
    public function retryDelay(
        RequestInterface $request,
        ResponseInterface $response,
        int $attempt,
        float $hintedDelay,
    ): ?float;

    /**
     * Whether the response is a rate limit rather than an ordinary error.
     * Consulted once retries stop: a rate-limited response then throws
     * RateLimitExceededException when Options::$throwOnRateLimit is set,
     * anything else is returned as is.
     */
    public function isRateLimited(ResponseInterface $response): bool;
}
