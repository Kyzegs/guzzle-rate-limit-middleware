<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Lock;

use Kyzegs\GuzzleRateLimitMiddleware\Exception\LockTimeoutException;
use Kyzegs\GuzzleRateLimitMiddleware\Lock\RedisLockFactory;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\RedisServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Redis;

final class RedisLockFactoryTest extends TestCase
{
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
     * @param callable(): (Redis|ClientInterface) $connect
     */
    #[DataProvider('clients')]
    public function test_a_held_lock_is_exclusive_until_released(callable $connect): void
    {
        $redis = $connect();
        $prefix = RedisServer::prefix($redis);
        $factory = new RedisLockFactory($redis, timeoutSeconds: 0.05, prefix: $prefix);

        $holder = $factory->make('bucket:a');
        $holder->acquire();

        $this->assertSame(1, $redis->exists($prefix . sha1('bucket:a')), 'The lock lives under the prefixed, hashed key.');
        $this->assertTimesOut(fn () => $factory->make('bucket:a')->acquire());

        // Other keys are independent.
        $other = $factory->make('bucket:b');
        $other->acquire();
        $other->release();

        $holder->release();

        $next = $factory->make('bucket:a');
        $next->acquire();
        $next->release();

        $this->assertSame(0, $redis->exists($prefix . sha1('bucket:a')));
    }

    /**
     * @param callable(): (Redis|ClientInterface) $connect
     */
    #[DataProvider('clients')]
    public function test_timeout_reports_the_key_and_the_timeout(callable $connect): void
    {
        $redis = $connect();
        $factory = new RedisLockFactory($redis, timeoutSeconds: 0.1, prefix: RedisServer::prefix($redis));
        $factory->make('global:x')->acquire();

        $started = microtime(true);

        try {
            $factory->make('global:x')->acquire();
            $this->fail('Expected the second acquire to time out.');
        } catch (LockTimeoutException $e) {
            $this->assertSame('global:x', $e->key);
            $this->assertSame(0.1, $e->timeoutSeconds);
            $this->assertStringContainsString('"global:x" within 0.10 seconds', $e->getMessage());
        }

        $elapsed = microtime(true) - $started;
        $this->assertGreaterThanOrEqual(0.1, $elapsed);
        $this->assertLessThan(1.0, $elapsed);
    }

    /**
     * @param callable(): (Redis|ClientInterface) $connect
     */
    #[DataProvider('clients')]
    public function test_a_waiter_gets_the_lock_once_it_expires(callable $connect): void
    {
        $redis = $connect();
        $prefix = RedisServer::prefix($redis);

        (new RedisLockFactory($redis, ttlSeconds: 0.1, prefix: $prefix))->make('bucket:a')->acquire();

        $waiter = (new RedisLockFactory($redis, timeoutSeconds: 2.0, prefix: $prefix))->make('bucket:a');
        $waiter->acquire();
        $waiter->release();

        $this->addToAssertionCount(1);
    }

    /**
     * @param callable(): (Redis|ClientInterface) $connect
     */
    #[DataProvider('clients')]
    public function test_an_expired_holder_never_releases_the_lock_someone_else_took(callable $connect): void
    {
        $redis = $connect();
        $prefix = RedisServer::prefix($redis);
        $shortLived = new RedisLockFactory($redis, ttlSeconds: 0.05, prefix: $prefix);
        $factory = new RedisLockFactory($redis, timeoutSeconds: 0.05, prefix: $prefix);

        $stale = $shortLived->make('bucket:a');
        $stale->acquire();
        usleep(100_000);

        $current = $factory->make('bucket:a');
        $current->acquire();

        $stale->release();

        $this->assertTimesOut(fn () => $factory->make('bucket:a')->acquire());

        $current->release();
    }

    /**
     * @param callable(): (Redis|ClientInterface) $connect
     */
    #[DataProvider('clients')]
    public function test_release_is_safe_without_acquire_and_when_repeated(callable $connect): void
    {
        $redis = $connect();
        $factory = new RedisLockFactory($redis, timeoutSeconds: 0.05, prefix: RedisServer::prefix($redis));

        $factory->make('bucket:a')->release();

        $lock = $factory->make('bucket:a');
        $lock->acquire();
        $lock->release();
        $lock->release();

        $again = $factory->make('bucket:a');
        $again->acquire();
        $again->release();

        $this->addToAssertionCount(1);
    }

    private function assertTimesOut(callable $acquire): void
    {
        try {
            $acquire();
        } catch (LockTimeoutException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected the lock to be held by someone else.');
    }
}
