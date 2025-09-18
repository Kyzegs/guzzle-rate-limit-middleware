<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\CacheHandlers;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\CacheHandlerInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Symfony-compatible cache handler that uses Symfony's Cache component.
 */
class SymfonyCacheHandler implements CacheHandlerInterface
{
    public function __construct(
        private readonly CacheInterface $cache
    ) {}

    public function get(string $key): mixed
    {
        try {
            return $this->cache->get($key, function() {
                return null; // Return null if key doesn't exist
            });
        } catch (\Throwable) {
            return null;
        }
    }

    public function put(string $key, mixed $value, int $ttl): bool
    {
        try {
            $this->cache->delete($key); // Remove existing key first
            $this->cache->get($key, function() use ($value) {
                return $value;
            }, $ttl);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function has(string $key): bool
    {
        try {
            $value = $this->cache->get($key, function() {
                throw new \Exception('Key not found');
            });
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function forget(string $key): bool
    {
        try {
            return $this->cache->delete($key);
        } catch (\Throwable) {
            return false;
        }
    }
}
