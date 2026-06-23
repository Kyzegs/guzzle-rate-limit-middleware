<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Support;

use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Support\BucketKeyResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\FakeClock;
use PHPUnit\Framework\TestCase;

final class BucketKeyResolverTest extends TestCase
{
    public function test_falls_back_to_route_key_before_discovery(): void
    {
        $resolver = new BucketKeyResolver(new InMemoryStore(new FakeClock()));

        $this->assertStringStartsWith('route:', $resolver->effective('GET /channels/{id}/messages'));
        $this->assertStringStartsWith('route:', $resolver->effective('GET /channels/{id}/messages', 'channels=1'));
    }

    public function test_effective_uses_discovered_hash_with_major_parameters(): void
    {
        $resolver = new BucketKeyResolver(new InMemoryStore(new FakeClock()));
        $route = 'GET /channels/{id}/messages';

        $resolver->observe($route, 'abc', 'channels=1');

        $this->assertStringStartsWith('bucket:', $resolver->effective($route, 'channels=1'));
    }

    public function test_hash_discovered_for_one_resource_applies_to_another(): void
    {
        $resolver = new BucketKeyResolver(new InMemoryStore(new FakeClock()));
        $route = 'GET /channels/{id}/messages';

        // Learn the hash from channel 1 ...
        $resolver->observe($route, 'abc', 'channels=1');

        // ... and channel 2 immediately resolves to the same hash, no rediscovery.
        $this->assertStringStartsWith('bucket:', $resolver->effective($route, 'channels=2'));
    }

    public function test_rekeys_and_drops_stale_state_when_hash_changes(): void
    {
        $store = new InMemoryStore(new FakeClock());
        $resolver = new BucketKeyResolver($store);
        $route = 'GET /channels/{id}/messages';

        $oldKey = $resolver->observe($route, 'old', 'channels=1');
        $store->put($oldKey, ['remaining' => 0], 60);

        $newKey = $resolver->observe($route, 'new', 'channels=1');

        $this->assertStringStartsWith('bucket:', $newKey);
        $this->assertNull($store->get($oldKey), 'Stale bucket state should be dropped on reassignment.');
    }
}
