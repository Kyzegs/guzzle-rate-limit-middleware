<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\StoreInterface;

/**
 * Records every write to bucket state made without that bucket's lock held.
 */
final class GuardedStore implements StoreInterface
{
    /** @var list<string> */
    public array $unguardedWrites = [];

    public function __construct(
        private readonly StoreInterface $inner,
        private readonly TrackingLockFactory $locks,
    ) {
    }

    public function get(string $key): ?array
    {
        return $this->inner->get($key);
    }

    public function put(string $key, array $data, int $ttl): bool
    {
        $isBucketState = str_starts_with($key, 'route:') || str_starts_with($key, 'bucket:');

        if ($isBucketState && ! isset($this->locks->held[$key])) {
            $this->unguardedWrites[] = $key;
        }

        return $this->inner->put($key, $data, $ttl);
    }

    public function forget(string $key): bool
    {
        return $this->inner->forget($key);
    }
}
