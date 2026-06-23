<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Store;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\StoreInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Store\FilesystemStore;
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Store\Psr16Store;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\ArrayCache;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\FakeClock;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{
    private string $tempDir;

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
    }

    /**
     * @return array<string, array{callable(FakeClock): StoreInterface}>
     */
    public static function storeProvider(): array
    {
        return [
            'in-memory' => [fn (FakeClock $clock): StoreInterface => new InMemoryStore($clock)],
            'filesystem' => [fn (FakeClock $clock): StoreInterface => new FilesystemStore(
                sys_get_temp_dir() . '/grl-test-' . bin2hex(random_bytes(6)),
                $clock,
            )],
            'psr16' => [fn (FakeClock $clock): StoreInterface => new Psr16Store(new ArrayCache())],
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

    public function test_in_memory_expiry(): void
    {
        $clock = new FakeClock(1_000.0);
        $store = new InMemoryStore($clock);

        $store->put('key', ['a' => 1], 30);
        $this->assertNotNull($store->get('key'));

        $clock->advance(31);
        $this->assertNull($store->get('key'));
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
