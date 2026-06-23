<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Store;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\StoreInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Adapter over any PSR-16 cache. This is the recommended way to get
 * cross-process state via Redis, Memcached, Laravel's cache, Symfony's cache,
 * etc. — wrap their PSR-16 implementation here.
 *
 * Keys are hashed/prefixed so they are always valid PSR-16 cache keys.
 */
final class Psr16Store implements StoreInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'grl:',
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        $value = $this->cache->get($this->key($key));

        return is_array($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function put(string $key, array $data, int $ttl): bool
    {
        return $this->cache->set($this->key($key), $data, $ttl);
    }

    public function forget(string $key): bool
    {
        return $this->cache->delete($this->key($key));
    }

    private function key(string $key): string
    {
        return $this->prefix . sha1($key);
    }
}
