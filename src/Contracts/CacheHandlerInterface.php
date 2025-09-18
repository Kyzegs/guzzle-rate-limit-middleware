<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

interface CacheHandlerInterface
{
    /**
     * Get a value from the cache.
     *
     * @param string $key
     * @return mixed
     */
    public function get(string $key): mixed;

    /**
     * Store a value in the cache.
     *
     * @param string $key
     * @param mixed $value
     * @param int $ttl Time to live in seconds
     * @return bool
     */
    public function put(string $key, mixed $value, int $ttl): bool;

    /**
     * Check if a key exists in the cache.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Remove a value from the cache.
     *
     * @param string $key
     * @return bool
     */
    public function forget(string $key): bool;
}
