<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\AtomicReservationStoreInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Reservation;
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * An atomic store whose reservations are scripted, for checking how the
 * handler reacts to each outcome without a Redis. Everything else is an
 * in-memory store.
 */
final class ScriptedReservationStore implements AtomicReservationStoreInterface
{
    /** @var list<array{key: string, now: float}> */
    public array $reservations = [];

    /** @var list<Reservation> */
    private array $script;

    public function __construct(private readonly InMemoryStore $store, Reservation ...$script)
    {
        $this->script = array_values($script);
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    public function merge(string $key, array $state, int $ttl, float $now): array
    {
        $this->put($key, $state, $ttl);

        return $state;
    }

    public function reserve(string $key, float $now): Reservation
    {
        $this->reservations[] = ['key' => $key, 'now' => $now];

        $next = array_shift($this->script);
        TestCase::assertNotNull($next, 'More reservations were asked for than scripted.');

        return $next;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        return $this->store->get($key);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function put(string $key, array $data, int $ttl): bool
    {
        return $this->store->put($key, $data, $ttl);
    }

    public function forget(string $key): bool
    {
        return $this->store->forget($key);
    }
}
