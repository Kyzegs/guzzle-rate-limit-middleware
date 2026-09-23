<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Config;

use InvalidArgumentException;

/**
 * Per-request overrides, read from the "rate_limit" Guzzle request option:
 *
 *     $client->get('/search', ['rate_limit' => ['max_delay' => 0]]);
 *
 * Every field is already resolved against the middleware's {@see Options}, so
 * the handler reads one place whether or not a request overrides anything, and
 * an absent option yields exactly the configured behaviour.
 *
 * Parsing is strict: a wrong type, a negative number or an unknown key throws
 * instead of being ignored, because a mistyped override would otherwise fall
 * back silently to the very default it was meant to change.
 */
final class RequestOptions
{
    /**
     * The Guzzle request option the overrides are read from.
     */
    public const KEY = 'rate_limit';

    private const KEYS = ['enabled', 'bucket', 'identity', 'max_delay', 'max_retries'];

    /**
     * @param bool        $enabled         False sends the request straight to the next handler.
     * @param string|null $bucket          Replaces the resolver's route key; null keeps the resolved one.
     * @param string|null $identity        Replaces the resolved identity, as supplied; the handler fingerprints it.
     * @param float|null  $maxDelaySeconds Longest wait allowed before throwing; null means no cap.
     * @param int         $maxRetries      Retries for a request that keeps receiving a retryable status.
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly ?string $bucket,
        public readonly ?string $identity,
        public readonly ?float $maxDelaySeconds,
        public readonly int $maxRetries,
    ) {
    }

    /**
     * Parse the raw option value. Null (or an absent option) overrides nothing.
     *
     * `max_delay` distinguishes absent from null: absent keeps
     * Options::$maxDelaySeconds, an explicit null lifts the cap for this request.
     *
     * @throws InvalidArgumentException when the value is not a valid override set.
     */
    public static function fromArray(mixed $value, Options $defaults): self
    {
        if ($value === null) {
            $value = [];
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException(sprintf(
                'The "%s" request option must be an array, got %s.',
                self::KEY,
                get_debug_type($value),
            ));
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown "%s" request option "%s"; expected one of: %s.',
                    self::KEY,
                    $key,
                    implode(', ', self::KEYS),
                ));
            }
        }

        return new self(
            enabled: self::enabled($value),
            bucket: self::nonEmptyString($value, 'bucket'),
            identity: self::nonEmptyString($value, 'identity'),
            maxDelaySeconds: array_key_exists('max_delay', $value)
                ? self::maxDelay($value['max_delay'])
                : $defaults->maxDelaySeconds,
            maxRetries: array_key_exists('max_retries', $value)
                ? self::maxRetries($value['max_retries'])
                : $defaults->maxRetries,
        );
    }

    /**
     * @param array<mixed> $value
     */
    private static function enabled(array $value): bool
    {
        if (! array_key_exists('enabled', $value)) {
            return true;
        }

        $enabled = $value['enabled'];

        if (! is_bool($enabled)) {
            throw self::invalid('enabled', 'a boolean', $enabled);
        }

        return $enabled;
    }

    /**
     * Null is rejected rather than read as "not overridden": a tenant id that
     * is unexpectedly null would otherwise merge that tenant's state into the
     * shared default identity without a trace.
     *
     * @param array<mixed> $value
     */
    private static function nonEmptyString(array $value, string $key): ?string
    {
        if (! array_key_exists($key, $value)) {
            return null;
        }

        $string = $value[$key];

        if (! is_string($string) || trim($string) === '') {
            throw self::invalid($key, 'a non-empty string', $string);
        }

        return $string;
    }

    private static function maxDelay(mixed $maxDelay): ?float
    {
        if ($maxDelay === null) {
            return null;
        }

        if ((! is_int($maxDelay) && ! is_float($maxDelay)) || is_nan((float) $maxDelay) || $maxDelay < 0) {
            throw self::invalid('max_delay', 'a non-negative number or null', $maxDelay);
        }

        return (float) $maxDelay;
    }

    private static function maxRetries(mixed $maxRetries): int
    {
        if (! is_int($maxRetries) || $maxRetries < 0) {
            throw self::invalid('max_retries', 'a non-negative integer', $maxRetries);
        }

        return $maxRetries;
    }

    /**
     * Numbers are echoed so a negative value is obvious; anything else only by
     * type, so a string (possibly a credential passed as the identity) never
     * ends up in an exception message.
     */
    private static function invalid(string $key, string $expected, mixed $actual): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'The "%s.%s" request option must be %s, got %s.',
            self::KEY,
            $key,
            $expected,
            is_int($actual) || is_float($actual) ? var_export($actual, true) : get_debug_type($actual),
        ));
    }
}
