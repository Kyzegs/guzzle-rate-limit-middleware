<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles;

use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;

/**
 * Minimal in-memory PSR-16 cache for exercising Psr16Store. TTL is ignored, but
 * keys are validated as strictly as real implementations do.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertValidKey($key);

        return $this->items[$key] ?? $default;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->assertValidKey($key);

        $this->items[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
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

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
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
