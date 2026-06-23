<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Resolver;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\BucketResolverInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Groups requests that share a rate limit by method, host and a normalised
 * path. Identifier-like path segments are collapsed to "{id}" so that, e.g.,
 * "/users/1" and "/users/2" — or two UUIDs — map to the same bucket.
 *
 * "Identifier" means numeric ids/snowflakes, UUIDs, and long hex tokens/hashes.
 * Human-readable slugs (e.g. "/repos/{owner}/{repo}") are intentionally left
 * literal because their shape is indistinguishable from route words; APIs that
 * key buckets on such segments want a custom {@see BucketResolverInterface}.
 */
final class DefaultBucketResolver implements BucketResolverInterface
{
    public function resolve(RequestInterface $request): string
    {
        $uri = $request->getUri();
        $path = $this->normalisePath($uri->getPath());

        return sprintf('%s %s%s', strtoupper($request->getMethod()), $uri->getHost(), $path);
    }

    private function normalisePath(string $path): string
    {
        $segments = array_map(
            fn (string $segment): string => $this->isIdentifier($segment) ? '{id}' : $segment,
            explode('/', $path),
        );

        return implode('/', $segments);
    }

    private function isIdentifier(string $segment): bool
    {
        if ($segment === '') {
            return false;
        }

        return ctype_digit($segment)                                                                          // 123, snowflakes
            || preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~i', $segment) === 1 // UUID
            || preg_match('~^[0-9a-f]{16,}$~i', $segment) === 1;                                              // long hex / hashes
    }
}
