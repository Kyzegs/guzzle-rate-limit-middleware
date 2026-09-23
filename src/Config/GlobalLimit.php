<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Config;

final class GlobalLimit
{
    public function __construct(
        public readonly int $maxRequests = 50,
        public readonly float $windowSeconds = 1.0,
    ) {
        Assert::atLeast('GlobalLimit::$maxRequests', $maxRequests, 1);
        Assert::positive('GlobalLimit::$windowSeconds', $windowSeconds);
    }
}
