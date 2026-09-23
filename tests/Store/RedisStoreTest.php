<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Store;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Reservation;
use Kyzegs\GuzzleRateLimitMiddleware\Store\RedisStore;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\RedisServer;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Redis;

final class RedisStoreTest extends TestCase
{
    private const NOW = 1_000_000.0;

    protected function tearDown(): void
    {
        RedisServer::cleanUp();
    }

    /**
     * @return array<string, array{callable(): (Redis|ClientInterface)}>
     */
    public static function clients(): array
    {
        return RedisServer::clients();
    }

    /**
     * @dataProvider clients
     * @param callable(): (Redis|ClientInterface) $connect
     */
    public function test_values_are_json_under_the_hashed_key_with_the_given_ttl(callable $connect): void
    {
        $redis = $connect();
        $prefix = RedisServer::prefix($redis);
        $store = new RedisStore($redis, $prefix);

        $store->put('bucket', ['remaining' => 5, 'reset' => 1_000_010.0], 120);

        $this->assertSame('{"remaining":5,"reset":1000010.0}', $redis->get($prefix . sha1('bucket')));
        $ttl = $redis->ttl($prefix . sha1('bucket'));
        $this->assertGreaterThan(110, $ttl);
        $this->assertLessThanOrEqual(120, $ttl);
    }

    /**
     * @dataProvider clients
     * @param callable(): (Redis|ClientInterface) $connect
     */
    public function test_a_non_positive_ttl_removes_the_value(callable $connect): void
    {
        $redis = $connect();
        $store = new RedisStore($redis, RedisServer::prefix($redis));

        $store->put('key', ['a' => 1], 60);
        $this->assertTrue($store->put('key', ['a' => 2], 0));

        $this->assertNull($store->get('key'));
    }

    /**
     * @dataProvider clients
     * @param callable(): (Redis|ClientInterface) $connect
     */
    public function test_an_unknown_bucket_hands_out_a_single_probe(callable $connect): void
    {
        $redis = $connect();
        $store = new RedisStore($redis, RedisServer::prefix($redis));

        $first = $store->reserve('bucket', self::NOW);
        $second = $store->reserve('bucket', self::NOW);

        $this->assertTrue($first->isProbe());
        $this->assertNotNull($first->probe);
        $this->assertTrue($second->isWait());
        $this->assertTrue($second->probeInFlight, 'Others wait for the probe, not for a window.');
        $this->assertGreaterThan(0.0, $second->delaySeconds);
        $this->assertLessThanOrEqual(0.05, $second->delaySeconds);
    }

    /**
     * @dataProvider clients
     * @param callable(): (Redis|ClientInterface) $connect
     */
    public function test_releasing_the_probe_lets_the_next_caller_probe(callable $connect): void
    {
        $redis = $connect();
        $store = new RedisStore($redis, RedisServer::prefix($redis));

        $this->probe($store->reserve('bucket', self::NOW))->release();

        $this->assertTrue($store->reserve('bucket', self::NOW)->isProbe());
    }

    /**
     * @dataProvider clients
     * @param callable(): (Redis|ClientInterface) $connect
     */
    public function test_a_lost_probe_expires_and_its_late_release_leaves_the_next_one(callable $connect): void
    {
        $redis = $connect();
        $store = new RedisStore($redis, RedisServer::prefix($redis), probeTtlSeconds: 0.1);

        $lost = $this->probe($store->reserve('bucket', self::NOW));
        $this->assertTrue($store->reserve('bucket', self::NOW)->probeInFlight);

        usleep(150_000);

        $next = $store->reserve('bucket', self::NOW);
        $this->assertTrue($next->isProbe(), 'An expired marker must not wedge the bucket.');

        $lost->release();

        $this->assertTrue(
            $store->reserve('bucket', self::NOW)->probeInFlight,
            'A stale prober must not release the marker another caller now holds.',
        );
    }

    /**
     * @dataProvider clients
     * @param callable(): (Redis|ClientInterface) $connect
     */
    public function test_reserves_the_remaining_slots_then_waits_for_the_reset(callable $connect): void
    {
        $redis = $connect();
        $prefix = RedisServer::prefix($redis);
        $store = new RedisStore($redis, $prefix);
        $store->put('bucket', (new RateLimit(limit: 5, remaining: 2, reset: self::NOW + 10.0, bucketHash: 'abcd'))->toArray(), 120);

        $this->assertTrue($store->reserve('bucket', self::NOW)->isReserved());
        $this->assertTrue($store->reserve('bucket', self::NOW + 1.0)->isReserved());

        $wait = $store->reserve('bucket', self::NOW + 2.5);
        $this->assertTrue($wait->isWait());
        $this->assertFalse($wait->probeInFlight);
        $this->assertEqualsWithDelta(7.5, $wait->delaySeconds, 0.001, 'The delay runs on the given clock.');

        $stored = $store->get('bucket');
        $this->assertNotNull($stored);
        $this->assertEquals(new RateLimit(limit: 5, remaining: 0, reset: self::NOW + 10.0, bucketHash: 'abcd'), RateLimit::fromArray($stored));
        $this->assertGreaterThan(110, $redis->ttl($prefix . sha1('bucket')), 'Reserving keeps the expiry put() chose.');
    }

    /**
     * @dataProvider clients
     * @param callable(): (Redis|ClientInterface) $connect
     */
    public function test_a_window_that_has_passed_is_probed_even_with_remaining_left(callable $connect): void
    {
        $redis = $connect();
        $store = new RedisStore($redis, RedisServer::prefix($redis));
        $store->put('bucket', (new RateLimit(limit: 5, remaining: 5, reset: self::NOW))->toArray(), 60);

        $this->assertTrue($store->reserve('bucket', self::NOW)->isProbe());
        $this->assertTrue($store->reserve('bucket', self::NOW)->probeInFlight);
    }

    /**
     * @dataProvider clients
     * @param callable(): (Redis|ClientInterface) $connect
     */
    public function test_persisting_the_probe_response_opens_the_bucket_to_waiters(callable $connect): void
    {
        $redis = $connect();
        $store = new RedisStore($redis, RedisServer::prefix($redis));

        $probe = $this->probe($store->reserve('bucket', self::NOW));
        $store->put('bucket', (new RateLimit(limit: 5, remaining: 4, reset: self::NOW + 5.0))->toArray(), 60);

        // Waiters need not wait for the release: the new window is enough.
        $this->assertTrue($store->reserve('bucket', self::NOW)->isReserved());

        $probe->release();
    }

    /**
     * @dataProvider clients
     * @param callable(): (Redis|ClientInterface) $connect
     */
    public function test_unreadable_state_is_treated_as_unknown(callable $connect): void
    {
        $redis = $connect();
        $prefix = RedisServer::prefix($redis);
        $store = new RedisStore($redis, $prefix);
        $redis->set($prefix . sha1('bucket'), 'not json');

        $this->assertNull($store->get('bucket'));
        $this->assertTrue($store->reserve('bucket', self::NOW)->isProbe());
    }

    private function probe(Reservation $reservation): LockInterface
    {
        $this->assertTrue($reservation->isProbe());
        $this->assertNotNull($reservation->probe);

        return $reservation->probe;
    }
}
