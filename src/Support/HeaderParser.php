<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Support;

use Psr\Http\Message\ResponseInterface;

final class HeaderParser
{
    /**
     * Read a single header value from a response, returning $fallback when the
     * header is absent, the name is null, or the value is an empty string.
     */
    public static function value(ResponseInterface $response, ?string $name, mixed $fallback = null): mixed
    {
        if ($name === null || ! $response->hasHeader($name)) {
            return $fallback;
        }

        $value = $response->getHeaderLine($name);

        return $value !== '' ? $value : $fallback;
    }

    /**
     * Whether a non-empty header is present.
     */
    public static function has(ResponseInterface $response, ?string $name): bool
    {
        return self::value($response, $name) !== null;
    }
}
