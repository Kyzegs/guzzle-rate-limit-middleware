<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Resolver;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\BucketResolverInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Puts every request to the same host in one bucket, for APIs that meter the
 * whole account instead of individual routes — e.g. Shopify's REST Admin API,
 * where every endpoint drains the same bucket per app and store. Keying those
 * by route would track dozens of separate allowances for what is really one.
 */
final class HostBucketResolver implements BucketResolverInterface
{
    public function resolve(RequestInterface $request): string
    {
        return strtolower($request->getUri()->getHost());
    }
}
