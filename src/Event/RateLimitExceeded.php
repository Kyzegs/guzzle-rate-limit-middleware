<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Event;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Dispatched right before a
 * {@see \Kyzegs\GuzzleRateLimitMiddleware\Exception\RateLimitExceededException}
 * is thrown, once retries are exhausted on a 429.
 *
 * It carries the same data as the exception, so a listener can record the
 * failure centrally even when the caller catches and swallows the exception.
 */
final class RateLimitExceeded
{
    public function __construct(
        public readonly RequestInterface $request,
        public readonly ResponseInterface $response,
        public readonly float $retryAfter,
        public readonly bool $global,
    ) {
    }
}
