<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Config;

use InvalidArgumentException;

final class GlobalLimit
{
    /**
     * @param list<string> $exemptPaths Regular expressions (delimiters included) matched against the
     *                                  request path. A matching request neither waits for nor spends
     *                                  the budget; use it for routes the API keeps outside its global
     *                                  limit. Nothing is exempt by default.
     */
    public function __construct(
        public readonly int $maxRequests = 50,
        public readonly float $windowSeconds = 1.0,
        public readonly array $exemptPaths = [],
    ) {
        Assert::atLeast('GlobalLimit::$maxRequests', $maxRequests, 1);
        Assert::positive('GlobalLimit::$windowSeconds', $windowSeconds);

        // A broken pattern would otherwise only surface as a warning on every
        // request while quietly exempting nothing; fail where it is written.
        foreach ($exemptPaths as $pattern) {
            if (! is_string($pattern) || @preg_match($pattern, '') === false) {
                throw new InvalidArgumentException(sprintf(
                    'Global limit exempt path %s is not a valid regular expression.',
                    is_string($pattern) ? '"' . $pattern . '"' : get_debug_type($pattern),
                ));
            }
        }
    }

    /**
     * Discord's bot-global budget: 50 requests per second, with interaction
     * callbacks exempt because Discord does not count them against it.
     *
     * @see https://discord.com/developers/docs/topics/rate-limits
     */
    public static function discord(): self
    {
        return new self(
            maxRequests: 50,
            windowSeconds: 1.0,
            exemptPaths: ['#/interactions/[^/]+/[^/]+/callback$#'],
        );
    }

    /**
     * A copy with $exemptPaths replacing the current list; the budget itself is
     * unchanged.
     *
     * Every constructor parameter is a promoted property of the same name, so
     * the copy is built from the object's own properties and a parameter added
     * later is carried over instead of silently reset to its default.
     *
     * @param list<string> $exemptPaths
     */
    public function withExemptPaths(array $exemptPaths): self
    {
        return new self(...[...get_object_vars($this), 'exemptPaths' => $exemptPaths]);
    }
}
