<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Event;

use Psr\Http\Message\RequestInterface;

/**
 * Dispatched right before an
 * {@see \Kyzegs\GuzzleRateLimitMiddleware\Exception\InvalidRequestLimitExceededException}
 * is thrown because the invalid-request safety budget is spent.
 *
 * The request is refused without being sent, so this is the only trace it
 * leaves besides the exception. For Discord it is the last warning before a
 * Cloudflare ban, which makes it worth paging on.
 */
final class InvalidRequestLimitReached
{
    public function __construct(
        public readonly RequestInterface $request,
        public readonly float $retryAfter,
    ) {
    }
}
