<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

interface StoreInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array;

    /**
     * @param array<string, mixed> $data
     */
    public function put(string $key, array $data, int $ttl): bool;

    public function forget(string $key): bool;
}
