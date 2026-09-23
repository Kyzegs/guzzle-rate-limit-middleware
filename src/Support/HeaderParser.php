<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Support;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
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
     * Read a Retry-After style header as seconds from $now (never negative).
     *
     * RFC 9110 allows either delay-seconds or an HTTP-date; casting a date to
     * a number silently turned it into "retry immediately". Returns null when
     * the header is absent or unparseable.
     */
    public static function delay(ResponseInterface $response, ?string $name, float $now): ?float
    {
        $value = self::value($response, $name);
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }

        try {
            // HTTP-dates are always GMT; the obsolete asctime form carries no
            // zone, so default to UTC rather than the server's timezone.
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }

        return max(0.0, (float) $date->format('U.u') - $now);
    }

    /**
     * Whether a non-empty header is present.
     */
    public static function has(ResponseInterface $response, ?string $name): bool
    {
        return self::value($response, $name) !== null;
    }
}
