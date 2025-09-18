<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\CacheHandlers;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\CacheHandlerInterface;

/**
 * File-based cache handler that persists data to the filesystem.
 */
class FileCacheHandler implements CacheHandlerInterface
{
    private string $cacheDir;

    public function __construct(string $cacheDir = null)
    {
        $this->cacheDir = $cacheDir ?: sprintf('%s/guzzle-rate-limit-cache', sys_get_temp_dir());
        
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    public function get(string $key): mixed
    {
        $filePath = $this->getFilePath($key);

        if (!file_exists($filePath)) {
            return null;
        }

        $data = unserialize(file_get_contents($filePath));

        if ($data['expiration'] < time()) {
            unlink($filePath);
            return null;
        }

        return $data['value'];
    }

    public function put(string $key, mixed $value, int $ttl): bool
    {
        $filePath = $this->getFilePath($key);
        $data = [
            'value' => $value,
            'expiration' => time() + $ttl,
        ];

        return file_put_contents($filePath, serialize($data)) !== false;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function forget(string $key): bool
    {
        $filePath = $this->getFilePath($key);

        if (file_exists($filePath)) {
            return unlink($filePath);
        }

        return true;
    }

    private function getFilePath(string $key): string
    {
        return sprintf('%s/%s.cache', $this->cacheDir, md5($key));
    }
}
