<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\RetryHandlers;

use Kyzegs\GuzzleRateLimitMiddleware\Configuration\RateLimitConfig;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LoggerInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RetryHandlerInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Exceptions\RateLimitExceededException;
use Kyzegs\GuzzleRateLimitMiddleware\Traits\DelayTrait;
use Kyzegs\GuzzleRateLimitMiddleware\Utilities\HeaderParser;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Standard retry handler that handles 429 and server errors.
 * Supports configurable global rate limit detection via headers.
 */
class StandardRetryHandler implements RetryHandlerInterface
{
    use DelayTrait;

    public function __construct(
        private readonly int $maxRetries = 5,
        private readonly ?RateLimitConfig $config = null
    ) {}

    public function shouldRetry(ResponseInterface $response, int $currentTries): bool
    {
        if ($currentTries >= $this->maxRetries) {
            return false;
        }

        $statusCode = $response->getStatusCode();
        
        // Retry on rate limits and server errors
        return $statusCode === 429 || in_array($statusCode, [500, 502, 504, 524], true);
    }

    public function getRetryDelay(ResponseInterface $response, int $currentTries): float
    {
        $statusCode = $response->getStatusCode();
        
        if ($statusCode === 429) {
            return (float) HeaderParser::getHeaderValue($response, $this->config?->retryAfterHeader, 0.0);
        }
        
        if (in_array($statusCode, [500, 502, 504, 524], true)) {
            // Exponential backoff for server errors
            return 1 + $currentTries * 2;
        }
        
        return 0.0;
    }

    public function executeRetry(
        RequestInterface $request,
        ResponseInterface $response,
        int $currentTries,
        LoggerInterface $logger,
        callable $onRateLimitReset = null
    ): bool {
        if (!$this->shouldRetry($response, $currentTries)) {
            return false;
        }

        $delay = $this->getRetryDelay($response, $currentTries);
        $statusCode = $response->getStatusCode();

        // Handle 429 responses
        if ($statusCode === 429) {
            $isGlobal = $this->isGlobalRateLimit($response);
            
            // Check if we should throw an exception instead of retrying
            if ($this->config?->throwOnRateLimit === true) {
                throw new RateLimitExceededException($request, $response, $delay, $isGlobal);
            }
            
            // Log the retry attempt
            if ($isGlobal) {
                $logger->warning(sprintf('Global rate limit has been hit. Retrying in %.2f seconds.', $delay));
            } else {
                $logger->warning(sprintf(
                    'We are being rate limited. %s %s responded with 429. Retrying in %.2f seconds.',
                    $request->getMethod(),
                    (string) $request->getUri(),
                    $delay
                ));
            }

            // Handle rate limit reset callback for 429 responses
            if ($onRateLimitReset !== null) {
                $onRateLimitReset();
            }
        } elseif (in_array($statusCode, [500, 502, 504, 524], true)) {
            $logger->debug(sprintf(
                'Encountered a %d status code. Retrying in %.0f seconds.',
                $statusCode,
                $delay
            ));
        }

        // Execute the delay
        $this->delay($delay);

        return true;
    }

    /**
     * Determine if a 429 response indicates a global rate limit.
     * Uses configurable headers only - more reliable than JSON body.
     */
    private function isGlobalRateLimit(ResponseInterface $response): bool
    {
        if ($this->config === null) {
            return false;
        }

        // Check X-RateLimit-Global header first (Discord: X-RateLimit-Global: true)
        $globalHeader = HeaderParser::getHeaderValue($response, $this->config->globalRateLimitHeader);
        if ($globalHeader !== null && strtolower($globalHeader) === 'true') {
            return true;
        }

        // Check X-RateLimit-Scope header (Discord: X-RateLimit-Scope: global)
        $scopeHeader = HeaderParser::getHeaderValue($response, $this->config->rateLimitScopeHeader);
        return $scopeHeader !== null && strtolower($scopeHeader) === strtolower($this->config->globalScopeValue);
    }
}
