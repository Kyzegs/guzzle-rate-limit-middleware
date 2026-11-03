<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Config;

use Closure;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;

/**
 * A self-imposed request budget, enforced before sending whether or not the
 * API reports its limits in response headers.
 *
 * Useful for APIs that document a limit but send no headers, for staying well
 * under a provider's limit, and for providers with several limits at once
 * (e.g. 10/s and 1,000/h): configure one Limit per rule and a request waits
 * until every one that applies to it has room.
 *
 * State lives in the middleware's store under a key derived from the limit's
 * settings and its scope, so processes sharing a store share the budget.
 */
final class Limit
{
    /**
     * @param int                                    $maxRequests   Requests allowed per window (the bucket capacity for TokenBucket).
     * @param float                                  $windowSeconds Length of the window in seconds.
     * @param LimitAlgorithm                         $algorithm     How requests are counted against the window.
     * @param LimitScope                             $scope         Which requests share the budget.
     * @param (Closure(RequestInterface): bool)|null $appliesTo     Restricts the limit to requests it returns true for; null applies it to all.
     * @param string                                 $name          Part of the storage key. Two limits with the same settings share
     *                                                              their state unless their names differ, so give distinct names to
     *                                                              limits that only differ in $appliesTo.
     */
    public function __construct(
        public readonly int $maxRequests,
        public readonly float $windowSeconds,
        public readonly LimitAlgorithm $algorithm = LimitAlgorithm::SlidingWindow,
        public readonly LimitScope $scope = LimitScope::Identity,
        public readonly ?Closure $appliesTo = null,
        public readonly string $name = '',
    ) {
        if ($maxRequests <= 0) {
            throw new InvalidArgumentException(sprintf('A limit must allow at least one request; got %d.', $maxRequests));
        }

        if (! is_finite($windowSeconds) || $windowSeconds <= 0.0) {
            throw new InvalidArgumentException(sprintf('A limit window must be a positive number of seconds; got %s.', is_nan($windowSeconds) ? 'NAN' : $windowSeconds));
        }
    }

    /**
     * @param (Closure(RequestInterface): bool)|null $appliesTo
     */
    public static function perSecond(
        int $maxRequests,
        LimitAlgorithm $algorithm = LimitAlgorithm::SlidingWindow,
        LimitScope $scope = LimitScope::Identity,
        ?Closure $appliesTo = null,
        string $name = '',
    ): self {
        return new self($maxRequests, 1.0, $algorithm, $scope, $appliesTo, $name);
    }

    /**
     * @param (Closure(RequestInterface): bool)|null $appliesTo
     */
    public static function perMinute(
        int $maxRequests,
        LimitAlgorithm $algorithm = LimitAlgorithm::SlidingWindow,
        LimitScope $scope = LimitScope::Identity,
        ?Closure $appliesTo = null,
        string $name = '',
    ): self {
        return new self($maxRequests, 60.0, $algorithm, $scope, $appliesTo, $name);
    }

    /**
     * @param (Closure(RequestInterface): bool)|null $appliesTo
     */
    public static function perHour(
        int $maxRequests,
        LimitAlgorithm $algorithm = LimitAlgorithm::SlidingWindow,
        LimitScope $scope = LimitScope::Identity,
        ?Closure $appliesTo = null,
        string $name = '',
    ): self {
        return new self($maxRequests, 3600.0, $algorithm, $scope, $appliesTo, $name);
    }

    /**
     * @param (Closure(RequestInterface): bool)|null $appliesTo
     */
    public static function perDay(
        int $maxRequests,
        LimitAlgorithm $algorithm = LimitAlgorithm::SlidingWindow,
        LimitScope $scope = LimitScope::Identity,
        ?Closure $appliesTo = null,
        string $name = '',
    ): self {
        return new self($maxRequests, 86400.0, $algorithm, $scope, $appliesTo, $name);
    }

    /**
     * Whether the request counts against this limit.
     */
    public function matches(RequestInterface $request): bool
    {
        return $this->appliesTo === null || (bool) ($this->appliesTo)($request);
    }
}
