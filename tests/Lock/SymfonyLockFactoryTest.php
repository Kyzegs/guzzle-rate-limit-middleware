<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Lock;

use Kyzegs\GuzzleRateLimitMiddleware\Exception\LockTimeoutException;
use Kyzegs\GuzzleRateLimitMiddleware\Lock\SymfonyLockFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

final class SymfonyLockFactoryTest extends TestCase
{
    private LockFactory $symfony;

    protected function setUp(): void
    {
        $this->symfony = new LockFactory(new InMemoryStore());
    }

    public function test_a_held_lock_excludes_other_holders_until_released(): void
    {
        $lock = (new SymfonyLockFactory($this->symfony))->make('bucket');

        $lock->acquire();
        $this->assertFalse($this->isFree('bucket'));

        $lock->release();
        $this->assertTrue($this->isFree('bucket'));
    }

    public function test_can_be_acquired_again_after_release(): void
    {
        $lock = (new SymfonyLockFactory($this->symfony))->make('bucket');

        $lock->acquire();
        $lock->release();
        $lock->acquire();

        $this->assertFalse($this->isFree('bucket'));

        $lock->release();
    }

    public function test_different_keys_do_not_contend(): void
    {
        $lock = (new SymfonyLockFactory($this->symfony))->make('first');
        $lock->acquire();

        $this->assertTrue($this->isFree('second'));

        $lock->release();
    }

    public function test_throws_once_the_timeout_passes(): void
    {
        $holder = (new SymfonyLockFactory($this->symfony))->make('bucket');
        $holder->acquire();

        $waiter = (new SymfonyLockFactory($this->symfony, timeoutSeconds: 0.05))->make('bucket');
        $start = microtime(true);

        try {
            $waiter->acquire();
            $this->fail('Expected a LockTimeoutException.');
        } catch (LockTimeoutException $e) {
            $this->assertSame('bucket', $e->key);
            $this->assertSame(0.05, $e->timeoutSeconds);
            $this->assertGreaterThanOrEqual(0.045, microtime(true) - $start);
        } finally {
            $holder->release();
        }

        $this->assertTrue($this->isFree('bucket'));
    }

    public function test_release_is_safe_without_acquire_and_when_repeated(): void
    {
        $lock = (new SymfonyLockFactory($this->symfony))->make('bucket');

        $lock->release();
        $lock->acquire();
        $lock->release();
        $lock->release();

        $this->assertTrue($this->isFree('bucket'));
    }

    public function test_locks_expire_after_the_configured_ttl(): void
    {
        $store = new class() implements PersistingStoreInterface {
            /** @var list<float> */
            public array $ttls = [];

            public function save(Key $key): void
            {
            }

            public function delete(Key $key): void
            {
            }

            public function exists(Key $key): bool
            {
                return false;
            }

            public function putOffExpiration(Key $key, float $ttl): void
            {
                $this->ttls[] = $ttl;
            }
        };

        $lock = (new SymfonyLockFactory(new LockFactory($store), ttlSeconds: 12.5))->make('bucket');
        $lock->acquire();
        $lock->release();

        $this->assertSame([12.5], $store->ttls);
    }

    private function isFree(string $key): bool
    {
        $probe = (new SymfonyLockFactory($this->symfony, timeoutSeconds: 0.0))->make($key);

        try {
            $probe->acquire();
        } catch (LockTimeoutException) {
            return false;
        }

        $probe->release();

        return true;
    }
}
