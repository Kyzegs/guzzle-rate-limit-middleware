<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

use Psr\Http\Message\RequestInterface;

/**
 * Interface for resolving route patterns and major parameters from requests.
 * 
 * This handles the static analysis of requests to determine:
 * - Route key (method + path template)
 * - Major parameters (that subdivide rate limit buckets)
 * 
 * Note: Bucket hashes are discovered dynamically from response headers,
 * not resolved from the request itself.
 */
interface RouteResolverInterface
{
    /**
     * Resolve the route key for the given request.
     * This should return a consistent key for the same API endpoint pattern.
     * 
     * Example: "GET /channels/{channel_id}/messages"
     *
     * @param RequestInterface $request
     * @return string
     */
    public function resolveRouteKey(RequestInterface $request): string;

    /**
     * Extract major parameters that subdivide rate limit buckets.
     * For Discord: channel_id, guild_id, webhook_id, webhook_token
     * For most APIs: empty string (no subdivision)
     *
     * @param RequestInterface $request
     * @return string
     */
    public function extractMajorParameters(RequestInterface $request): string;

    /**
     * Get the full fallback key when no bucket hash is discovered.
     * This combines route key + major parameters.
     *
     * @param RequestInterface $request
     * @return string
     */
    public function getFallbackKey(RequestInterface $request): string;
}
