<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Exception;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Thrown when a request keeps receiving rate-limited responses (a 429 by
 * default; see RetryStrategyInterface::isRateLimited()) after the retries have
 * been exhausted (and Options::$throwOnRateLimit is true).
 *
 * The message names the route, not the URI, because URIs can carry credentials
 * (Discord webhook and interaction tokens, API keys in a query string) and
 * exception messages end up in logs and error trackers. getRequest() still has
 * the whole URI, so treat it as sensitive.
 */
final class RateLimitExceededException extends RuntimeException
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ?ResponseInterface $response = null,
        private readonly float $retryAfter = 0.0,
        private readonly bool $global = false,
        ?string $route = null,
    ) {
        parent::__construct(sprintf(
            'Rate limit exceeded for %s. Retry after %.2f seconds.%s',
            $route ?? $request->getMethod() . ' ' . $request->getUri()->getHost(),
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
