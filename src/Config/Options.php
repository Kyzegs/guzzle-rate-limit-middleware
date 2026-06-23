<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Config;

/**
 * Behavioural tuning for the middleware (separate from header names, which live
 * in {@see Headers}).
 */
final class Options
{
    /**
     * @param int        $maxRetries          Retries for a request that still receives a retryable status.
     * @param float      $safetyBufferSeconds Extra seconds added to every computed delay to absorb clock skew / latency.
     * @param float      $jitterPercent       Random extra delay, as a percentage (0-100) of the base delay.
     * @param bool       $throwOnRateLimit    Throw RateLimitExceededException once retries are exhausted on a 429.
     * @param int        $maxStoreTtl         Upper bound (seconds) for how long bucket state is cached.
     * @param int[]      $retryStatusCodes    HTTP status codes that trigger a retry.
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
    ) {
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
