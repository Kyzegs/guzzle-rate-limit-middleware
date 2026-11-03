<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Config;

use InvalidArgumentException;

/**
 * Constructor guards for the config objects. A bad value here would otherwise
 * surface much later as a request that never waits, never retries or sleeps
 * forever, far from the line that configured it.
 *
 * @internal
 */
final class Assert
{
    public static function atLeast(string $name, int|float $value, int|float $minimum): void
    {
        if (is_nan((float) $value) || $value < $minimum) {
            throw new InvalidArgumentException(sprintf('%s must be at least %s, got %s.', $name, $minimum, is_nan((float) $value) ? 'NAN' : $value));
        }
    }

    public static function positive(string $name, int|float $value): void
    {
        if (! is_finite((float) $value) || $value <= 0) {
            throw new InvalidArgumentException(sprintf('%s must be greater than 0, got %s.', $name, is_nan((float) $value) ? 'NAN' : $value));
        }
    }

    public static function between(string $name, float $value, float $minimum, float $maximum): void
    {
        if (is_nan($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException(sprintf('%s must be between %s and %s, got %s.', $name, $minimum, $maximum, is_nan($value) ? 'NAN' : $value));
        }
    }

    /**
     * @param array<mixed> $codes
     */
    public static function statusCodes(string $name, array $codes): void
    {
        foreach ($codes as $code) {
            if (! is_int($code) || $code < 100 || $code > 599) {
                $shown = is_int($code) ? (string) $code : get_debug_type($code);

                throw new InvalidArgumentException(sprintf('%s must only contain HTTP status codes (100-599), got %s.', $name, $shown));
            }
        }
    }
}
