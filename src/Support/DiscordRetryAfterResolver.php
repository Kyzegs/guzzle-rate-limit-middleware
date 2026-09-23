<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Support;

use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\ClockInterface;
use Psr\Http\Message\ResponseInterface;

final class DiscordRetryAfterResolver extends DefaultRetryAfterResolver
{
    public function resolve(ResponseInterface $response, Headers $headers, ClockInterface $clock): float
    {
        $header = HeaderParser::delay($response, $headers->retryAfter, $clock->now());
        if ($header !== null) {
            return $header;
        }

        $retryAfter = JsonBody::decode($response)['retry_after'] ?? null;
        if (is_numeric($retryAfter)) {
            return max(0.0, (float) $retryAfter);
        }

        return parent::resolve($response, $headers, $clock);
    }
}
