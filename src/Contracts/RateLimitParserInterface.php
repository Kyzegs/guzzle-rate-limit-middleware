<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

use Kyzegs\GuzzleRateLimitMiddleware\RateLimit;
use Psr\Http\Message\ResponseInterface;

/**
 * Reads the rate-limit state a response reports.
 *
 * {@see \Kyzegs\GuzzleRateLimitMiddleware\Parser\HeaderRateLimitParser} covers
 * APIs that send one number per header. Anything that packs the allowance into
 * a single field — "used/limit" fractions, IETF structured fields, several
 * policies at once — needs its own parser.
 *
 * A parser only answers "how much is left and when does it come back". The
 * handler still reads Retry-After, the bucket hash, and the global/scope flags
 * through {@see \Kyzegs\GuzzleRateLimitMiddleware\Config\Headers}.
 */
interface RateLimitParserInterface
{
    /**
     * Return null when the response carries no rate-limit information. The
     * handler then leaves the stored state for the bucket as it is, rather
     * than overwriting what earlier responses reported with an empty snapshot.
     */
    public function parse(ResponseInterface $response, ClockInterface $clock): ?RateLimit;
}
