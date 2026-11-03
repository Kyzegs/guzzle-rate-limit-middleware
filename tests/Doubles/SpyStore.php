<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\StoreInterface;

/**
 * Decorates a store and records every key it is asked about, so tests can
 * assert what a request touched (or that it touched nothing at all).
 */
final class SpyStore implements StoreInterface
{
    /** @var list<string> */
    public array $reads = [];

    /** @var list<string> Keys written or forgotten. */
    public array $writes = [];

    public function __construct(private readonly StoreInterface $inner)
    {
    }

    public function get(string $key): ?array
    {
        $this->reads[] = $key;

        return $this->inner->get($key);
    }

    public function put(string $key, array $data, int $ttl): bool
    {
        $this->writes[] = $key;

        return $this->inner->put($key, $data, $ttl);
    }

    public function forget(string $key): bool
    {
        $this->writes[] = $key;

        return $this->inner->forget($key);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_values(array_unique([...$this->reads, ...$this->writes]));
    }
}
