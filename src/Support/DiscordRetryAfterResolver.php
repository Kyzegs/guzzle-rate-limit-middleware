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
        $header = HeaderParser::value($response, $headers->retryAfter);
        if ($header !== null) {
            return max(0.0, (float) $header);
        }

        $body = $response->getBody();
        $position = $body->isSeekable() ? $body->tell() : null;
        $contents = (string) $body;
        if ($position !== null) {
            $body->seek($position);
        }

        $decoded = json_decode($contents, true);
        if (is_array($decoded) && is_numeric($decoded['retry_after'] ?? null)) {
            return max(0.0, (float) $decoded['retry_after']);
        }

        return parent::resolve($response, $headers, $clock);
    }
}
