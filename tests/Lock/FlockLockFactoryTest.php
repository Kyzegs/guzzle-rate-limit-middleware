<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Lock;

use Kyzegs\GuzzleRateLimitMiddleware\Exception\LockTimeoutException;
use Kyzegs\GuzzleRateLimitMiddleware\Lock\FlockLockFactory;
use PHPUnit\Framework\TestCase;

final class FlockLockFactoryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/grl-lock-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            array_map('unlink', glob($this->directory . '/*') ?: []);
            rmdir($this->directory);
        }
    }

    public function test_creates_the_lock_directory(): void
    {
        new FlockLockFactory($this->directory . '/nested');

        $this->assertDirectoryExists($this->directory . '/nested');

        rmdir($this->directory . '/nested');
    }

    public function test_names_lock_files_after_a_hash_of_the_key(): void
    {
        $key = 'route:GET api.test /users/../../etc/passwd';
        $lock = (new FlockLockFactory($this->directory))->make($key);

        $lock->acquire();
        $lock->release();

        $this->assertSame([sha1($key) . '.lock'], array_values(array_diff(scandir($this->directory) ?: [], ['.', '..'])));
    }

    public function test_a_held_lock_excludes_other_holders_until_released(): void
    {
        $lock = (new FlockLockFactory($this->directory))->make('bucket');

        $lock->acquire();
        $this->assertFalse($this->isFree('bucket'));

        $lock->release();
        $this->assertTrue($this->isFree('bucket'));
    }

    public function test_can_be_acquired_again_after_release(): void
    {
        $lock = (new FlockLockFactory($this->directory))->make('bucket');

        $lock->acquire();
        $lock->release();
        $lock->acquire();

        $this->assertFalse($this->isFree('bucket'));

        $lock->release();
    }

    public function test_acquire_while_held_is_a_no_op(): void
    {
        // A second handle on the same file would contend with the first; the
        // zero timeout turns that mistake into an exception instead of a hang.
        $lock = (new FlockLockFactory($this->directory, timeoutSeconds: 0.0))->make('bucket');

        $lock->acquire();
        $lock->acquire();
        $lock->release();

        $this->assertTrue($this->isFree('bucket'));
    }

    public function test_different_keys_do_not_contend(): void
    {
        $lock = (new FlockLockFactory($this->directory))->make('first');
        $lock->acquire();

        $this->assertTrue($this->isFree('second'));

        $lock->release();
    }

    public function test_throws_once_the_timeout_passes(): void
    {
        $holder = (new FlockLockFactory($this->directory))->make('bucket');
        $holder->acquire();

        $waiter = (new FlockLockFactory($this->directory, timeoutSeconds: 0.05, pollIntervalSeconds: 0.005))->make('bucket');
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

        // The failed attempt must not have kept anything open.
        $waiter->release();
        $this->assertTrue($this->isFree('bucket'));
    }

    public function test_release_is_safe_without_acquire_and_when_repeated(): void
    {
        $lock = (new FlockLockFactory($this->directory))->make('bucket');

        $lock->release();
        $lock->acquire();
        $lock->release();
        $lock->release();

        $this->assertTrue($this->isFree('bucket'));
    }

    private function isFree(string $key): bool
    {
        $probe = (new FlockLockFactory($this->directory, timeoutSeconds: 0.0))->make($key);

        try {
            $probe->acquire();
        } catch (LockTimeoutException) {
            return false;
        }

        $probe->release();

        return true;
    }
}
