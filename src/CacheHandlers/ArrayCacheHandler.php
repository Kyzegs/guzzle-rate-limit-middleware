<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\CacheHandlers;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\CacheHandlerInterface;

/**
 * Simple in-memory array cache handler.
 * Note: This cache will not persist between requests.
 */
class ArrayCacheHandler implements CacheHandlerInterface
{
    /** @var array */
    private array $cache = [];

    /** @var array */
    private array $expiration = [];

    public function get(string $key): mixed
    {
        if (!$this->has($key)) {
            return null;
        }

        return $this->cache[$key];
    }

    public function put(string $key, mixed $value, int $ttl): bool
    {
        $this->cache[$key] = $value;
        $this->expiration[$key] = time() + $ttl;

        return true;
    }

    public function has(string $key): bool
    {
        if (!isset($this->cache[$key])) {
            return false;
        }

        if (isset($this->expiration[$key]) && $this->expiration[$key] < time()) {
            unset($this->cache[$key], $this->expiration[$key]);
            return false;
        }

        return true;
    }

    public function forget(string $key): bool
    {
        unset($this->cache[$key], $this->expiration[$key]);
        return true;
    }
}
