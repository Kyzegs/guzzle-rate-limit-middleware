<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Config;

use InvalidArgumentException;

/**
 * Behavioural tuning for the middleware (separate from header names, which live
 * in {@see Headers}).
 */
final class Options
{
    /**
     * $maxRetries and $retryStatusCodes configure the default
     * StatusCodeRetryStrategy; a retry strategy passed to the middleware
     * brings its own.
     *
     * @param int        $maxRetries          Retries for a request that still receives a retryable status.
     * @param float      $safetyBufferSeconds Extra seconds added to every computed delay to absorb clock skew / latency.
     * @param float      $jitterPercent       Random extra delay, as a percentage (0-100) of the base delay.
     * @param bool       $throwOnRateLimit    Throw RateLimitExceededException once retries stop on a response the retry
     *                                        strategy calls rate-limited (a 429 by default).
     * @param int        $maxStoreTtl         Upper bound (seconds) for how long bucket state is cached.
     * @param int[]      $retryStatusCodes    HTTP status codes that trigger a retry.
     * @param float|null $maxDelaySeconds     Longest delay to accept; a longer one throws RateLimitDelayExceededException.
     * @param list<Limit> $limits             Client-side budgets every applicable request must fit before it is sent.
     *
     * @throws \InvalidArgumentException When a value is out of range.
     */
    public function __construct(
        public readonly int $maxRetries = 3,
        public readonly float $safetyBufferSeconds = 1.0,
        public readonly float $jitterPercent = 0.0,
        public readonly bool $throwOnRateLimit = true,
        public readonly int $maxStoreTtl = 604800, // 7 days
        public readonly array $retryStatusCodes = [429],
        public readonly ?GlobalLimit $globalLimit = null,
        public readonly ?InvalidRequestLimit $invalidRequestLimit = null,
        public readonly ?float $maxDelaySeconds = null,
        public readonly array $limits = [],
    ) {
        Assert::atLeast('Options::$maxRetries', $maxRetries, 0);
        Assert::atLeast('Options::$safetyBufferSeconds', $safetyBufferSeconds, 0);
        Assert::between('Options::$jitterPercent', $jitterPercent, 0, 100);
        Assert::atLeast('Options::$maxStoreTtl', $maxStoreTtl, 1);
        Assert::statusCodes('Options::$retryStatusCodes', $retryStatusCodes);

        if ($maxDelaySeconds !== null) {
            Assert::atLeast('Options::$maxDelaySeconds', $maxDelaySeconds, 0);
        }

        foreach ($limits as $limit) {
            if (! $limit instanceof Limit) {
                throw new InvalidArgumentException(sprintf('Options::$limits may only contain %s instances.', Limit::class));
            }
        }
    }

    /**
     * A copy with $globalLimit replacing the current one; every other option is
     * unchanged.
     *
     * Every constructor parameter is a promoted property of the same name, so
     * the copy is built from the object's own properties and an option added
     * later is carried over instead of silently reset to its default.
     */
    public function withGlobalLimit(?GlobalLimit $globalLimit): self
    {
        return new self(...[...get_object_vars($this), 'globalLimit' => $globalLimit]);
    }

    /**
     * Balanced defaults suitable for most APIs.
     */
    public static function default(): self
    {
        return new self();
    }

    /**
     * Larger safety margins and more retries; favours never hitting 429 over speed.
     */
    public static function conservative(): self
    {
        return new self(
            maxRetries: 5,
            safetyBufferSeconds: 2.0,
            jitterPercent: 10.0,
        );
    }

    /**
     * Minimal margins; favours throughput and accepts the occasional 429.
     */
    public static function aggressive(): self
    {
        return new self(
            maxRetries: 1,
            safetyBufferSeconds: 0.1,
            jitterPercent: 0.0,
        );
    }
}
