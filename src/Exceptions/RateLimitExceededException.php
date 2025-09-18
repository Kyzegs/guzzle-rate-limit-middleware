<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\Exceptions;

use Exception;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class RateLimitExceededException extends Exception
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResponseInterface $response,
        private readonly float $resetAfter,
        private readonly bool $isGlobal = false
    ) {
        $message = sprintf(
            '%s rate limit exceeded for %s %s. Reset after %.2f seconds.',
            $this->isGlobal ? 'Global' : 'Route',
            $this->request->getMethod(),
            (string) $this->request->getUri(),
            $this->resetAfter
        );

        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }

    public function getResetAfter(): float
    {
        return $this->resetAfter;
    }

    public function isGlobal(): bool
    {
        return $this->isGlobal;
    }
}
