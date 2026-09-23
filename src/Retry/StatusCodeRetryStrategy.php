<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Retry;

use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RetryStrategyInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Retry a fixed set of status codes a fixed number of times, waiting as long
 * as the response asks.
 *
 * This is the default, built from Options::$maxRetries and
 * Options::$retryStatusCodes, and only a 429 counts as a rate limit: any
 * other status in the list is retried but handed back quietly once the
 * retries run out.
 */
final class StatusCodeRetryStrategy implements RetryStrategyInterface
{
    /**
     * @param int   $maxRetries  Retries for a request that keeps receiving one of $statusCodes.
     * @param int[] $statusCodes HTTP status codes that trigger a retry.
     */
    public function __construct(
        private readonly int $maxRetries = 3,
        private readonly array $statusCodes = [429],
    ) {
    }

    public static function fromOptions(Options $options): self
    {
        return new self($options->maxRetries, $options->retryStatusCodes);
    }

    public function retryDelay(
        RequestInterface $request,
        ResponseInterface $response,
        int $attempt,
        float $hintedDelay,
    ): ?float {
        if ($attempt > $this->maxRetries || ! in_array($response->getStatusCode(), $this->statusCodes, true)) {
            return null;
        }

        return $hintedDelay;
    }

    public function isRateLimited(ResponseInterface $response): bool
    {
        return $response->getStatusCode() === 429;
    }
}
