<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockFactoryInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockInterface;

final class SpyLockFactory implements LockFactoryInterface
{
    public int $acquired = 0;

    public int $released = 0;

    /** @var list<string> */
    public array $keys = [];

    public function make(string $key): LockInterface
    {
        $this->keys[] = $key;

        return new class($this) implements LockInterface {
            public function __construct(private readonly SpyLockFactory $factory)
            {
            }

            public function acquire(): void
            {
                $this->factory->acquired++;
            }

            public function release(): void
            {
                $this->factory->released++;
            }
        };
    }
}
