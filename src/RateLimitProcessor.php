<?php

namespace Kyzegs\GuzzleRateLimitMiddleware;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\BucketHashDiscoveryInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockHandlerInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LoggerInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RetryHandlerInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Traits\DelayTrait;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Processes rate-limited requests with proper locking, retries, and bucket hash discovery.
 * 
 * This class follows the Single Responsibility Principle by focusing solely on
 * the request processing workflow.
 */
class RateLimitProcessor
{
    use DelayTrait;

    public function __construct(
        private readonly BucketManager $bucketManager,
        private readonly LockHandlerInterface $lockHandler,
        private readonly LoggerInterface $logger,
        private readonly RetryHandlerInterface $retryHandler,
        private readonly BucketHashDiscoveryInterface $bucketHashDiscovery
    ) {}

    /**
     * Process a request with rate limiting, retries, and bucket hash discovery.
     */
    public function process(callable $handler, RequestInterface $request, array $options)
    {
        $bucketKey = $this->bucketManager->routeResolver->getFallbackKey($request);
        $lock = $this->lockHandler->lock(sprintf('rate_limit_lock:%s', $bucketKey));
        $tries = 0;

        while (true) {
            $lock->block(PHP_INT_MAX);
            
            try {
                // Check if we need to delay for rate limiting
                $this->handleRateLimitDelay($request, $bucketKey);

                // Make the request
                $promise = $handler($request, $options);
                $response = $promise instanceof PromiseInterface ? $promise->wait() : $promise;
                
                // Handle bucket hash discovery
                $this->bucketHashDiscovery->handleDiscovery($request, $response, $this->logger);
                
                // Update rate limit from response
                $this->bucketManager->updateFromResponse($request, $response);
                
                // Check if we should retry
                if ($this->retryHandler->executeRetry($request, $response, $tries, $this->logger, function() use ($request) {
                    // Reset rate limit state for 429 responses
                    $rateLimit = $this->bucketManager->getRateLimit($request);
                    $rateLimit->reset();
                })) {
                    $tries++;
                    continue;
                }

                // Log if bucket is exhausted (successful response)
                if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                    $rateLimit = $this->bucketManager->getRateLimit($request);
                    if ($rateLimit->getRemaining() === 0) {
                        $this->logger->debug(sprintf(
                            'Rate limit bucket (%s) has been exhausted. Pre-emptively rate limiting...',
                            $bucketKey
                        ));
                    }
                }

                // Return response (success or final failure)
                if ($promise instanceof PromiseInterface) {
                    return Create::promiseFor($response);
                }
                
                return $response;
                
            } finally {
                $lock->release();
            }
        }
    }

    /**
     * Handle rate limit delays before making the request.
     */
    private function handleRateLimitDelay(RequestInterface $request, string $bucketKey): void
    {
        $rateLimit = $this->bucketManager->getRateLimit($request);
        
        if ($rateLimit->shouldDelay()) {
            $sleepSeconds = $rateLimit->getResetAfter();
            if ($sleepSeconds > 0) {
                $this->logger->debug(sprintf(
                    'Sleeping rate limit bucket %s for %.2f seconds.',
                    $bucketKey,
                    $sleepSeconds
                ));
                
                $this->delay($sleepSeconds);
            }
        }
    }

}
 