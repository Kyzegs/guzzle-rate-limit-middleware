<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles;

use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;

/**
 * Minimal in-memory PSR-16 cache for exercising Psr16Store. TTL is ignored, but
 * keys are validated as strictly as real implementations do. Its signatures
 * stay loose so it loads against every supported psr/simple-cache major.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $items = [];

    /**
     * @param string $key
     */
    public function get($key, $default = null): mixed
    {
        $this->assertValidKey($key);

        return $this->items[$key] ?? $default;
    }

    /**
     * @param string                 $key
     * @param \DateInterval|int|null $ttl
     */
    public function set($key, $value, $ttl = null): bool
    {
        $this->assertValidKey($key);

        $this->items[$key] = $value;

        return true;
    }

    /**
     * @param string $key
     */
    public function delete($key): bool
    {
        $this->assertValidKey($key);

        unset($this->items[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    /**
     * @param iterable<string> $keys
     *
     * @return iterable<string, mixed>
     */
    public function getMultiple($keys, $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * @param iterable<string, mixed> $values
     * @param \DateInterval|int|null  $ttl
     */
    public function setMultiple($values, $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    /**
     * @param string $key
     */
    public function has($key): bool
    {
        $this->assertValidKey($key);

        return isset($this->items[$key]);
    }

    private function assertValidKey(string $key): void
    {
        if ($key === '' || strpbrk($key, '{}()/\\@:') !== false) {
            throw new InvalidArgumentException(sprintf('Cache key "%s" is empty or contains reserved characters.', $key));
        }
    }
}
