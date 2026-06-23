<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Config;

final class InvalidRequestLimit
{
    /** @param list<int> $statusCodes */
    public function __construct(
        public readonly int $maxRequests = 9000,
        public readonly float $windowSeconds = 600.0,
        public readonly array $statusCodes = [401, 403, 429],
    ) {}
}
