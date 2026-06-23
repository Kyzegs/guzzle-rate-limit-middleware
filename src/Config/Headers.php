<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Config;

final class Headers
{
    public function __construct(
        public string $limit = 'X-RateLimit-Limit',
        public string $remaining = 'X-RateLimit-Remaining',
        public string $reset = 'X-RateLimit-Reset',
        public ?string $resetAfter = null,
        public ?string $bucket = null,
        public ?string $global = null,
        public ?string $scope = null,
        public string $retryAfter = 'Retry-After',
    ) {}

    public static function discord(): self
    {
        return new self(
            limit: 'X-RateLimit-Limit',
            remaining: 'X-RateLimit-Remaining',
            reset: 'X-RateLimit-Reset',
            resetAfter: 'X-RateLimit-Reset-After',
            bucket: 'X-RateLimit-Bucket',
            global: 'X-RateLimit-Global',
            scope: 'X-RateLimit-Scope',
            retryAfter: 'Retry-After',
        );
    }

    public static function github(): self
    {
        return new self(
            limit: 'X-RateLimit-Limit',
            remaining: 'X-RateLimit-Remaining',
            reset: 'X-RateLimit-Reset',
            retryAfter: 'Retry-After',
        );
    }

    public static function twitter(): self
    {
        return new self(
            limit: 'x-rate-limit-limit',
            remaining: 'x-rate-limit-remaining',
            reset: 'x-rate-limit-reset',
            retryAfter: 'Retry-After',
        );
    }

    public static function ietf(): self
    {
        return new self(
            limit: 'RateLimit-Limit',
            remaining: 'RateLimit-Remaining',
            reset: 'RateLimit-Reset',
            resetAfter: 'RateLimit-Reset-After',
            bucket: null,
            global: null,
            scope: null,
            retryAfter: 'Retry-After',
        );
    }

    public function needsToCheckBucketHeader(): bool
    {
        return $this->bucket !== null;
    }
}