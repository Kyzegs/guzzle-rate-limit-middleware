<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Support;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\StoreInterface;

/**
 * Resolves the effective storage key for a request, supporting dynamic
 * bucket-hash discovery as implemented by Discord.
 *
 * Mirrors discord.py: a route is identified by a template route key (major
 * parameters excluded) mapped to a discovered bucket hash. The actual bucket —
 * what state is stored under — is "{hash}:{major}" once the hash is known, or
 * the route key + major parameters as a fallback before discovery. Because the
 * hash map is keyed by the template, a hash learned for one resource (e.g. one
 * channel) immediately applies to every other resource on the same route.
 *
 * @see https://github.com/Rapptz/discord.py/blob/master/discord/http.py
 */
final class BucketKeyResolver
{
    private const MAP_PREFIX = 'bucketmap:';

    private const MAP_TTL = 604800; // 7 days

    public function __construct(private readonly StoreInterface $store)
    {
    }

    /**
     * The key the rate-limit state should currently be read from / written to.
     */
    public function effective(string $routeKey, string $major = ''): string
    {
        $hash = $this->knownHash($routeKey);

        return $hash !== null
            ? $this->bucketKey($hash, $major)
            : $this->fallbackKey($routeKey, $major);
    }

    /**
     * Record that $routeKey maps to $bucketHash and return the effective key to
     * store state under. If the route was previously mapped to a different hash,
     * the stale entry for these major parameters is dropped.
     */
    public function observe(string $routeKey, string $bucketHash, string $major = ''): string
    {
        $previous = $this->knownHash($routeKey);

        if ($previous !== null && $previous !== $bucketHash) {
            $this->store->forget($this->bucketKey($previous, $major));
        }

        if ($previous !== $bucketHash) {
            $this->store->put($this->mapKey($routeKey), ['hash' => $bucketHash], self::MAP_TTL);
        }

        return $this->bucketKey($bucketHash, $major);
    }

    private function knownHash(string $routeKey): ?string
    {
        $hash = $this->store->get($this->mapKey($routeKey))['hash'] ?? null;

        return is_string($hash) ? $hash : null;
    }

    private function bucketKey(string $bucketHash, string $major): string
    {
        return 'bucket:' . hash('sha256', $bucketHash . "\0" . $major);
    }

    private function fallbackKey(string $routeKey, string $major): string
    {
        return 'route:' . hash('sha256', $routeKey . "\0" . $major);
    }

    private function mapKey(string $routeKey): string
    {
        return self::MAP_PREFIX . sha1($routeKey);
    }
}
