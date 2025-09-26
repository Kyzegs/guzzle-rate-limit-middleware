<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

use Psr\Http\Message\RequestInterface;

/**
 * Interface for handling rate-limited requests.
 * 
 * This interface allows applications to implement custom logic for handling
 * requests when rate limits are encountered, such as queuing, retrying,
 * or custom delay strategies.
 */
interface HandlerInterface
{
    /**
     * Handle a request with rate limiting considerations.
     * 
     * This method is called by the middleware to process each request.
     * Implementations can decide how to handle rate limits, retries,
     * and any custom logic needed for their specific use case.
     *
     * @param callable $handler The original Guzzle handler to execute the request
     * @param RequestInterface $request The PSR-7 request to process
     * @param array $options Guzzle request options
     * @return mixed Response or promise from the handler
     */
    public function handle(callable $handler, RequestInterface $request, array $options): mixed;
}
