<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Retry;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RetryStrategyInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Support\HeaderParser;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * GitHub's documented rules for rate-limit errors.
 *
 * GitHub answers a rate limit with a 403 or a 429, so the status alone can't
 * tell a rate limit from a missing permission:
 *
 * - Primary limit: `x-ratelimit-remaining: 0`. Wait until `x-ratelimit-reset`.
 * - Secondary limit with `retry-after`: wait that many seconds.
 * - Secondary limit without either header (a 429, or a 403 whose message says
 *   so): wait at least a minute, doubling for each retry after that.
 * - Any other 403 is a permissions error; retrying won't change the answer.
 *
 * The first two waits come straight from the hinted delay, which the default
 * RetryAfterResolver reads from exactly those headers.
 */
final class GitHubRetryStrategy implements RetryStrategyInterface
{
    /**
     * GitHub's floor for a secondary limit that doesn't say how long to wait.
     */
    private const SECONDARY_MINIMUM_SECONDS = 60.0;

    /**
     * @param int $maxRetries Retries for a request that keeps hitting a rate limit.
     */
    public function __construct(
        private readonly int $maxRetries = 3,
    ) {
    }

    public function retryDelay(
        RequestInterface $request,
        ResponseInterface $response,
        int $attempt,
        float $hintedDelay,
    ): ?float {
        if ($attempt > $this->maxRetries || ! $this->isRateLimited($response)) {
            return null;
        }

        if ($this->saysHowLong($response)) {
            return $hintedDelay;
        }

        // Not the hint: with no Retry-After it falls back to x-ratelimit-reset,
        // the primary window's reset, which can be most of an hour away and
        // has nothing to do with a secondary limit.
        return self::SECONDARY_MINIMUM_SECONDS * 2 ** max(0, $attempt - 1);
    }

    public function isRateLimited(ResponseInterface $response): bool
    {
        return match ($response->getStatusCode()) {
            429 => true,
            403 => $this->saysHowLong($response) || $this->mentionsSecondaryLimit($response),
            default => false,
        };
    }

    /**
     * Whether the response carries the wait itself: Retry-After on a
     * secondary limit, or an exhausted primary limit that resets at
     * x-ratelimit-reset.
     */
    private function saysHowLong(ResponseInterface $response): bool
    {
        return HeaderParser::has($response, 'Retry-After')
            || HeaderParser::value($response, 'X-RateLimit-Remaining') === '0';
    }

    /**
     * A secondary limit may come back as a 403 with neither header, and then
     * only the message ("You have exceeded a secondary rate limit") sets it
     * apart from a permissions error.
     */
    private function mentionsSecondaryLimit(ResponseInterface $response): bool
    {
        $body = $response->getBody();

        // Reading a stream that can't be rewound would hand the caller an
        // empty body.
        if (! $body->isSeekable()) {
            return false;
        }

        $position = $body->tell();
        $contents = (string) $body;
        $body->seek($position);

        return stripos($contents, 'secondary rate limit') !== false;
    }
}
