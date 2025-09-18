<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\CacheHandlers;

use Illuminate\Support\Facades\Cache;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\CacheHandlerInterface;

/**
 * Laravel-compatible cache handler that uses Laravel's Cache facade.
 * Use this to integrate with Laravel's caching system.
 */
class LaravelCacheHandler implements CacheHandlerInterface
{
    public function get(string $key): mixed
    {
        return Cache::get($key);
    }

    public function put(string $key, mixed $value, int $ttl): bool
    {
        return Cache::put($key, $value, $ttl);
    }

    public function has(string $key): bool
    {
        return Cache::has($key);
    }

    public function forget(string $key): bool
    {
        return Cache::forget($key);
    }
}
