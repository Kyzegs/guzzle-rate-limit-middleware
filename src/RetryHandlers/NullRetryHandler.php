<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\RetryHandlers;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LoggerInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RetryHandlerInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Null retry handler that never retries requests.
 * Used for simple rate limiting without retry logic.
 */
class NullRetryHandler implements RetryHandlerInterface
{
    public function shouldRetry(ResponseInterface $response, int $currentTries): bool
    {
        return false;
    }

    public function getRetryDelay(ResponseInterface $response, int $currentTries): float
    {
        return 0.0;
    }

    public function executeRetry(
        RequestInterface $request,
        ResponseInterface $response,
        int $currentTries,
        LoggerInterface $logger,
        callable $onRateLimitReset = null
    ): bool {
        return false; // Never retry
    }
}
