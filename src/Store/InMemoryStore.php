<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Store;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\ClockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\StoreInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Support\SystemClock;

/**
 * Process-local store. State lives only for the lifetime of the PHP process,
 * so it does not provide cross-process rate limiting. Useful for tests and
 * single long-running workers.
 */
final class InMemoryStore implements StoreInterface
{
    /**
     * Expired entries are otherwise only dropped when their own key is read,
     * which never happens to most per-channel or per-resource buckets. A long
     * running worker would keep them all.
     */
    private const SWEEP_EVERY_WRITES = 1000;

    private int $writes = 0;

    /** @var array<string, array{data: array<string, mixed>, expires: float}> */
    private array $items = [];

    public function __construct(private readonly ClockInterface $clock = new SystemClock())
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        $item = $this->items[$key] ?? null;

        if ($item === null) {
            return null;
        }

        if ($item['expires'] <= $this->clock->now()) {
            unset($this->items[$key]);

            return null;
        }

        return $item['data'];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function put(string $key, array $data, int $ttl): bool
    {
        $this->items[$key] = [
            'data' => $data,
            'expires' => $this->clock->now() + $ttl,
        ];

        if (++$this->writes % self::SWEEP_EVERY_WRITES === 0) {
            $now = $this->clock->now();
            $this->items = array_filter($this->items, static fn (array $item): bool => $item['expires'] > $now);
        }

        return true;
    }

    public function forget(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }
}
