<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Parser;

use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\ClockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RateLimitParserInterface;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimit;
use Psr\Http\Message\ResponseInterface;

/**
 * Reads one number per header, with the header names taken from
 * {@see Headers}. This is the default parser and otherwise behaves exactly like
 * {@see RateLimit::fromResponse()}.
 *
 * A response carrying none of those headers (a proxy's error page, an unmetered
 * route) yields null: it says nothing about the bucket.
 */
final class HeaderRateLimitParser implements RateLimitParserInterface
{
    public function __construct(private readonly Headers $headers = new Headers())
    {
    }

    public function parse(ResponseInterface $response, ClockInterface $clock): ?RateLimit
    {
        if (! $this->headers->presentIn($response)) {
            return null;
        }

        return RateLimit::fromResponse($response, $this->headers, $clock);
    }
}
