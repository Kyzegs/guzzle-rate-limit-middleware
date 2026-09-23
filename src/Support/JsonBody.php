<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Support;

use Psr\Http\Message\ResponseInterface;

final class JsonBody
{
    /**
     * Decode a JSON object body without taking it away from the caller.
     *
     * Returns null for a non-seekable (streamed) body: reading it here would
     * leave the application with an empty body, and no rate-limit hint is
     * worth that.
     *
     * @return array<mixed>|null
     */
    public static function decode(ResponseInterface $response): ?array
    {
        $body = $response->getBody();
        if (! $body->isSeekable()) {
            return null;
        }

        $position = $body->tell();
        $contents = (string) $body;
        $body->seek($position);

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }
}
