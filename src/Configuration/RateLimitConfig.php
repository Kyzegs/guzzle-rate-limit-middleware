<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\Configuration;

class RateLimitConfig
{
    /**
     * @param string $remainingHeader The header name for remaining requests
     * @param string|null $limitHeader The header name for total limit (optional)
     * @param string|null $resetHeader The header name for reset time (e.g. 'x-ratelimit-reset')
     * @param int $safeguardSeconds Extra seconds to add as a safeguard
     * @param int $maxCacheTtl Maximum cache TTL in seconds
     * @param bool $respectRetryAfter Whether to respect the Retry-After header
     * @param string|null $retryAfterHeader The header name for retry after (defaults to 'retry-after')
     * @param string|null $bucketHashHeader The header name for bucket hash discovery (e.g. 'x-ratelimit-bucket')
     * @param string|null $globalRateLimitHeader The header name for global rate limit detection (e.g. 'x-ratelimit-global')
     * @param string|null $rateLimitScopeHeader The header name for rate limit scope (e.g. 'x-ratelimit-scope')
     * @param string $globalScopeValue The value that indicates global scope (e.g. 'global')
     * @param bool $throwOnRateLimit Whether to throw an exception when rate limited (defaults to true)
     */
    public function __construct(
        public readonly string $remainingHeader = 'x-ratelimit-remaining',
        public readonly ?string $limitHeader = 'x-ratelimit-limit',
        public readonly ?string $resetHeader = null,
        public readonly int $safeguardSeconds = 4,
        public readonly int $maxCacheTtl = 604800, // 7 days
        public readonly bool $respectRetryAfter = true,
        public readonly ?string $retryAfterHeader = 'retry-after',
        public readonly ?string $bucketHashHeader = null,
        public readonly ?string $globalRateLimitHeader = null,
        public readonly ?string $rateLimitScopeHeader = null,
        public readonly string $globalScopeValue = 'global',
        public readonly bool $throwOnRateLimit = true
    ) {}

    /**
     * Create a configuration for Discord API rate limiting.
     *
     * @return static
     */
    public static function discord(): static
    {
        return new static(
            remainingHeader: 'x-ratelimit-remaining',
            limitHeader: 'x-ratelimit-limit',
            resetHeader: 'x-ratelimit-reset',
            safeguardSeconds: 1,
            respectRetryAfter: true,
            retryAfterHeader: 'x-ratelimit-reset-after',
            bucketHashHeader: 'x-ratelimit-bucket',
            globalRateLimitHeader: 'x-ratelimit-global',
            rateLimitScopeHeader: 'x-ratelimit-scope',
            globalScopeValue: 'global'
        );
    }

    /**
     * Create a configuration for GitHub API rate limiting.
     *
     * @return static
     */
    public static function github(): static
    {
        return new static(
            remainingHeader: 'x-ratelimit-remaining',
            limitHeader: 'x-ratelimit-limit',
            resetHeader: 'x-ratelimit-reset',
            safeguardSeconds: 2
        );
    }

    /**
     * Create a configuration for Twitter API rate limiting.
     *
     * @return static
     */
    public static function twitter(): static
    {
        return new static(
            remainingHeader: 'x-rate-limit-remaining',
            limitHeader: 'x-rate-limit-limit',
            resetHeader: 'x-rate-limit-reset',
            safeguardSeconds: 3
        );
    }

    /**
     * Create a custom configuration.
     *
     * @param array $config
     * @return static
     */
    public static function custom(array $config): static
    {
        return new static(
            remainingHeader: $config['remaining_header'] ?? 'x-ratelimit-remaining',
            limitHeader: $config['limit_header'] ?? 'x-ratelimit-limit',
            resetHeader: $config['reset_header'] ?? null,
            safeguardSeconds: $config['safeguard_seconds'] ?? 4,
            maxCacheTtl: $config['max_cache_ttl'] ?? 604800,
            respectRetryAfter: $config['respect_retry_after'] ?? true,
            retryAfterHeader: $config['retry_after_header'] ?? 'retry-after',
            bucketHashHeader: $config['bucket_hash_header'] ?? null,
            globalRateLimitHeader: $config['global_rate_limit_header'] ?? null,
            rateLimitScopeHeader: $config['rate_limit_scope_header'] ?? null,
            globalScopeValue: $config['global_scope_value'] ?? 'global',
            throwOnRateLimit: $config['throw_on_rate_limit'] ?? true
        );
    }
}
