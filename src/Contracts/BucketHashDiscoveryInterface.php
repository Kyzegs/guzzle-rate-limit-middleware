<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Interface for handling bucket hash discovery from API responses.
 */
interface BucketHashDiscoveryInterface
{
    /**
     * Handle bucket hash discovery from the response.
     * This updates the bucket hash mapping when APIs provide dynamic bucket information.
     */
    public function handleDiscovery(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void;

    /**
     * Check if bucket hash discovery is enabled.
     */
    public function isEnabled(): bool;
}
