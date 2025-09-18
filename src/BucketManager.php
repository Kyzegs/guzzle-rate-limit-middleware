<?php

namespace Kyzegs\GuzzleRateLimitMiddleware;

use Kyzegs\GuzzleRateLimitMiddleware\Configuration\RateLimitConfig;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RouteResolverInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\CacheHandlerInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Manages rate limit buckets and their states.
 */
class BucketManager
{
    public function __construct(
        public readonly CacheHandlerInterface $cacheHandler,
        public readonly RouteResolverInterface $routeResolver,
        public readonly RateLimitConfig $config
    ) {}

    /**
     * Get the rate limit state for a request.
     */
    public function getRateLimit(RequestInterface $request): RateLimit
    {
        $bucketKey = $this->getEffectiveBucketKey($request);
        $cacheKey = sprintf('rate_limit:%s', $bucketKey);
        
        $rateLimit = $this->cacheHandler->get($cacheKey);
        
        if (!$rateLimit instanceof RateLimit) {
            $rateLimit = new RateLimit();
        }
        
        return $rateLimit;
    }

    /**
     * Store the rate limit state for a request.
     */
    public function storeRateLimit(RequestInterface $request, RateLimit $rateLimit): void
    {
        $bucketKey = $this->getEffectiveBucketKey($request);
        $cacheKey = sprintf('rate_limit:%s', $bucketKey);
        
        // Store with appropriate TTL
        $ttl = min(
            max((int) ceil($rateLimit->getResetAfter()) + $this->config->safeguardSeconds, 60),
            $this->config->maxCacheTtl
        );
        
        $this->cacheHandler->put($cacheKey, $rateLimit, $ttl);
    }

    /**
     * Update rate limit from response and store it.
     */
    public function updateFromResponse(RequestInterface $request, ResponseInterface $response): RateLimit
    {
        $rateLimit = $this->getRateLimit($request);
        
        // Update from response headers (includes retry-after handling)
        $rateLimit->updateFromResponse($response, $this->config);
        $this->storeRateLimit($request, $rateLimit);
        
        return $rateLimit;
    }

    /**
     * Check if a request should be delayed and return delay in microseconds.
     */
    public function getDelay(RequestInterface $request): float
    {
        $rateLimit = $this->getRateLimit($request);
        return $rateLimit->getDelayMicroseconds();
    }

    /**
     * Get the effective bucket key, considering discovered bucket hashes.
     * 
     * This matches Python discord.py logic:
     * - Try bucket_hash + major_parameters first
     * - Fall back to route_key + major_parameters
     */
    private function getEffectiveBucketKey(RequestInterface $request): string
    {
        $routeKey = $this->routeResolver->resolveRouteKey($request);
        $hashKey = sprintf('bucket_hash:%s', $routeKey);
        $bucketHash = $this->cacheHandler->get($hashKey);
        $majorParams = $this->routeResolver->extractMajorParameters($request);
        
        if ($bucketHash !== null) {
            // Use discovered bucket hash + major parameters (like Python)
            return $majorParams ? sprintf('%s:%s', $bucketHash, $majorParams) : $bucketHash;
        }
        
        // Fall back to route key + major parameters
        return $this->routeResolver->getFallbackKey($request);
    }
}
