<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Support;

use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\ClockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RetryAfterResolverInterface;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimit;
use Psr\Http\Message\ResponseInterface;

class DefaultRetryAfterResolver implements RetryAfterResolverInterface
{
    public function resolve(ResponseInterface $response, Headers $headers, ClockInterface $clock): float
    {
        $retryAfter = HeaderParser::value($response, $headers->retryAfter);

        return $retryAfter !== null
            ? max(0.0, (float) $retryAfter)
            : RateLimit::fromResponse($response, $headers, $clock)->secondsUntilReset($clock);
    }
}
