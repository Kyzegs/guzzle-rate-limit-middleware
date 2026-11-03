<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\StoreInterface;
use LogicException;

/**
 * Array store that remembers every write, for asserting what a preset
 * persisted without knowing how it derives its keys. TTL is ignored.
 */
final class RecordingStore implements StoreInterface
{
    /** @var list<array{key: string, data: array<string, mixed>}> */
    public array $puts = [];

    /** @var array<string, array<string, mixed>> */
    private array $items = [];

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        return $this->items[$key] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function put(string $key, array $data, int $ttl): bool
    {
        $this->items[$key] = $data;
        $this->puts[] = ['key' => $key, 'data' => $data];

        return true;
    }

    public function forget(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function lastPut(): array
    {
        $last = end($this->puts);

        if ($last === false) {
            throw new LogicException('Nothing was stored.');
        }

        return $last['data'];
    }
}
