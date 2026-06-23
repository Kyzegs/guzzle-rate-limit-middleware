<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Exception;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Thrown when a request keeps receiving 429 responses after the configured
 * number of retries has been exhausted (and Options::$throwOnRateLimit is true).
 */
final class RateLimitExceededException extends RuntimeException
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ?ResponseInterface $response = null,
        private readonly float $retryAfter = 0.0,
        private readonly bool $global = false,
    ) {
        parent::__construct(sprintf(
            'Rate limit exceeded for %s %s. Retry after %.2f seconds.%s',
            $request->getMethod(),
            (string) $request->getUri(),
            $retryAfter,
            $global ? ' (global rate limit)' : '',
        ));
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }

    public function getRetryAfter(): float
    {
        return $this->retryAfter;
    }

    public function isGlobal(): bool
    {
        return $this->global;
    }
}
