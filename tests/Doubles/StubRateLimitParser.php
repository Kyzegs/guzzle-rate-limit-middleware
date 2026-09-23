<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\ClockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RateLimitParserInterface;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimit;
use Psr\Http\Message\ResponseInterface;

/**
 * Hands out a scripted result per response, ignoring the response itself, so
 * handler tests can pin exactly what a parser reports. Returns null once the
 * script runs out.
 */
final class StubRateLimitParser implements RateLimitParserInterface
{
    public int $calls = 0;

    /**
     * @param list<RateLimit|null> $results
     */
    public function __construct(private array $results)
    {
    }

    public function parse(ResponseInterface $response, ClockInterface $clock): ?RateLimit
    {
        $this->calls++;

        return array_shift($this->results);
    }
}
