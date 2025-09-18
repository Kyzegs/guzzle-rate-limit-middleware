<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\BucketHashHandlers;

use Kyzegs\GuzzleRateLimitMiddleware\BucketManager;
use Kyzegs\GuzzleRateLimitMiddleware\Configuration\RateLimitConfig;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\BucketHashDiscoveryInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LoggerInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Handles dynamic bucket hash discovery from API responses.
 * This is primarily used by Discord API which provides bucket hashes in response headers.
 */
class BucketHashDiscovery implements BucketHashDiscoveryInterface
{
    public function __construct(
        private readonly BucketManager $bucketManager,
        private readonly bool $enabled = false
    ) {}

    public function handleDiscovery(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        if (!$this->enabled) {
            return;
        }

        $bucketHashHeader = $this->bucketManager->config->bucketHashHeader;
        
        // Skip if no bucket hash header is configured
        if ($bucketHashHeader === null) {
            return;
        }
        
        if (!$response->hasHeader($bucketHashHeader)) {
            return;
        }
        
        $bucketHash = $response->getHeaderLine($bucketHashHeader);
        if ($bucketHash === '') {
            return;
        }
        
        $routeKey = $this->bucketManager->routeResolver->resolveRouteKey($request);
        $currentHashKey = sprintf('bucket_hash:%s', $routeKey);
        $currentHash = $this->bucketManager->cacheHandler->get($currentHashKey);
        
        if ($currentHash !== $bucketHash) {
            if ($currentHash !== null) {
                $logger->debug(sprintf(
                    'A route (%s) has changed hashes: %s -> %s.',
                    $routeKey,
                    $currentHash,
                    $bucketHash
                ));
                
                // Clear old rate limit data with old hash
                $majorParams = $this->bucketManager->routeResolver->extractMajorParameters($request);
                $oldFullKey = $majorParams ? sprintf('%s:%s', $currentHash, $majorParams) : $currentHash;
                $oldCacheKey = sprintf('rate_limit:%s', $oldFullKey);
                $this->bucketManager->cacheHandler->forget($oldCacheKey);
            } else {
                $logger->debug(sprintf(
                    '%s has found its initial rate limit bucket hash (%s).',
                    $routeKey,
                    $bucketHash
                ));
            }
            
            // Store new hash
            $this->bucketManager->cacheHandler->put($currentHashKey, $bucketHash, 86400); // 24 hours
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
