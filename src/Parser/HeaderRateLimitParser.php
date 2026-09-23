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
 * {@see Headers}. This is the default parser and behaves exactly like
 * {@see RateLimit::fromResponse()}.
 *
 * It currently always returns a snapshot, even for a response without any
 * rate-limit headers, because that is what the handler has always persisted.
 */
final class HeaderRateLimitParser implements RateLimitParserInterface
{
    public function __construct(private readonly Headers $headers = new Headers())
    {
    }

    public function parse(ResponseInterface $response, ClockInterface $clock): ?RateLimit
    {
        return RateLimit::fromResponse($response, $this->headers, $clock);
    }
}
