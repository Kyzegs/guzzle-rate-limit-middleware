<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Config;

final class InvalidRequestLimit
{
    /**
     * @param list<int>    $statusCodes   Response statuses that count as invalid requests.
     * @param list<string> $ignoredScopes Values of the configured scope header (see {@see Headers::$scope})
     *                                    whose 429s do not count, compared case-insensitively. Discord
     *                                    leaves "shared" 429s out of its invalid-request limit — another
     *                                    caller spent that per-resource allowance — hence the default.
     */
    public function __construct(
        public readonly int $maxRequests = 9000,
        public readonly float $windowSeconds = 600.0,
        public readonly array $statusCodes = [401, 403, 429],
        public readonly array $ignoredScopes = ['shared'],
    ) {}
}
