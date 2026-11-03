<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Store;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\StoreInterface;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Store\FilesystemStore;
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Store\Psr16Store;
use Kyzegs\GuzzleRateLimitMiddleware\Store\RedisStore;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\ArrayCache;
use Kyzegs\GuzzleRateLimitMiddleware\Testing\FakeClock;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\RedisServer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class StoreTest extends TestCase
{
    private string $tempDir;

    /** @var list<string> */
    private static array $providedDirs = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/grl-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            array_map('unlink', glob($this->tempDir . '/*') ?: []);
            rmdir($this->tempDir);
        }

        foreach (self::$providedDirs as $dir) {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }

        RedisServer::cleanUp();
    }

    /**
     * @return array<string, array{callable(FakeClock): StoreInterface}>
     */
    public static function storeProvider(): array
    {
        return [
            'in-memory' => [fn (FakeClock $clock): StoreInterface => new InMemoryStore($clock)],
            'filesystem' => [static function (FakeClock $clock): StoreInterface {
                $dir = sys_get_temp_dir() . '/grl-test-' . bin2hex(random_bytes(6));
                self::$providedDirs[] = $dir;

                return new FilesystemStore($dir, $clock);
            }],
            'psr16' => [fn (FakeClock $clock): StoreInterface => new Psr16Store(new ArrayCache())],
            'redis (phpredis)' => [function (FakeClock $clock): StoreInterface {
                $redis = RedisServer::phpredis();

                return new RedisStore($redis, RedisServer::prefix($redis));
            }],
            'redis (predis)' => [function (FakeClock $clock): StoreInterface {
                $redis = RedisServer::predis();

                return new RedisStore($redis, RedisServer::prefix($redis));
            }],
        ];
    }

    /**
     * @dataProvider storeProvider
     * @param callable(FakeClock): StoreInterface $factory
     */
    public function test_put_get_forget(callable $factory): void
    {
        $store = $factory(new FakeClock());

        $this->assertNull($store->get('missing'));

        $store->put('key', ['remaining' => 5], 60);
        $this->assertSame(['remaining' => 5], $store->get('key'));

        $store->forget('key');
        $this->assertNull($store->get('key'));
    }

    /**
     * @dataProvider storeProvider
     * @param callable(FakeClock): StoreInterface $factory
     */
    public function test_round_trips_rate_limit_state_per_key(callable $factory): void
    {
        $store = $factory(new FakeClock());
        $bucket = new RateLimit(limit: 10, remaining: 4, reset: 1_000_010.25, bucketHash: 'abcd');
        $other = new RateLimit(limit: 1, remaining: 1, reset: 1_000_001.0);

        $store->put('bucket', $bucket->toArray(), 60);
        $store->put('other', $other->toArray(), 60);

        $this->assertEquals($bucket, RateLimit::fromArray($store->get('bucket') ?? []));
        $this->assertEquals($other, RateLimit::fromArray($store->get('other') ?? []));
    }

    public function test_in_memory_expiry(): void
    {
        $clock = new FakeClock(1_000.0);
        $store = new InMemoryStore($clock);

        $store->put('key', ['a' => 1], 30);
        $this->assertNotNull($store->get('key'));

        $clock->advance(31);
        $this->assertNull($store->get('key'));
    }

    public function test_in_memory_store_purges_what_has_expired(): void
    {
        $clock = new FakeClock(1_000.0);
        $store = new InMemoryStore($clock);

        for ($i = 0; $i < 999; $i++) {
            $store->put("old-{$i}", ['a' => 1], 10);
        }

        $clock->advance(11);
        $store->put('fresh', ['a' => 1], 10);

        $items = (new ReflectionProperty($store, 'items'))->getValue($store);
        $this->assertSame(['fresh'], array_keys($items));
    }

    public function test_filesystem_store_purges_what_has_expired(): void
    {
        $clock = new FakeClock(1_000.0);
        $store = new FilesystemStore($this->tempDir, $clock, sweepEvery: 1);

        $store->put('old', ['a' => 1], 10);
        $store->put('live', ['a' => 1], 100);
        $this->assertCount(2, glob($this->tempDir . '/*.json') ?: []);

        $clock->advance(11);
        $store->put('fresh', ['a' => 1], 100);

        $this->assertCount(2, glob($this->tempDir . '/*.json') ?: [], 'Only the expired file goes.');
        $this->assertNotNull($store->get('live'));
        $this->assertNotNull($store->get('fresh'));
    }

    public function test_filesystem_store_ignores_a_tampered_file(): void
    {
        $store = new FilesystemStore($this->tempDir, new FakeClock());
        $store->put('key', ['a' => 1], 60);

        foreach (glob($this->tempDir . '/*.json') ?: [] as $file) {
            file_put_contents($file, '{"expires":"soon","data":"nope"}');
        }

        $this->assertNull($store->get('key'));
    }

    public function test_filesystem_store_rejects_a_sweep_rate_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FilesystemStore($this->tempDir, new FakeClock(), sweepEvery: 0);
    }

    public function test_filesystem_expiry(): void
    {
        $clock = new FakeClock(1_000.0);
        $store = new FilesystemStore($this->tempDir, $clock);

        $store->put('key', ['a' => 1], 30);
        $this->assertNotNull($store->get('key'));

        $clock->advance(31);
        $this->assertNull($store->get('key'));
    }
}
