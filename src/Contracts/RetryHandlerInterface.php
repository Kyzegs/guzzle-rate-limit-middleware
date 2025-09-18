<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Interface for handling retries on failed requests.
 */
interface RetryHandlerInterface
{
    /**
     * Determine if a response should be retried.
     */
    public function shouldRetry(ResponseInterface $response, int $currentTries): bool;

    /**
     * Get the delay before retrying (in seconds).
     */
    public function getRetryDelay(ResponseInterface $response, int $currentTries): float;

    /**
     * Execute the full retry process including delay and logging.
     * Returns true if retry was executed, false if no retry should be attempted.
     */
    public function executeRetry(
        RequestInterface $request,
        ResponseInterface $response,
        int $currentTries,
        LoggerInterface $logger,
        callable $onRateLimitReset = null
    ): bool;
}
