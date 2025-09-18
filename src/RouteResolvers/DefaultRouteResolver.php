<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\RouteResolvers;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RouteResolverInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Default route resolver that treats each unique URL as its own route.
 * This works for most simple APIs that don't have complex bucket logic.
 */
class DefaultRouteResolver implements RouteResolverInterface
{
    public function resolveRouteKey(RequestInterface $request): string
    {
        $uri = $request->getUri();
        return sprintf('%s %s://%s%s', $request->getMethod(), $uri->getScheme(), $uri->getHost(), $uri->getPath());
    }

    public function extractMajorParameters(RequestInterface $request): string
    {
        // Most APIs don't have major parameters
        return '';
    }

    public function getFallbackKey(RequestInterface $request): string
    {
        return $this->resolveRouteKey($request);
    }
}
