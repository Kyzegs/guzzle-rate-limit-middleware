<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\BucketHashHandlers;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\BucketHashDiscoveryInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LoggerInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Null bucket hash discovery that does nothing.
 * Used when bucket hash discovery is not needed.
 */
class NullBucketHashDiscovery implements BucketHashDiscoveryInterface
{
    public function handleDiscovery(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        // Do nothing
    }

    public function isEnabled(): bool
    {
        return false;
    }
}
