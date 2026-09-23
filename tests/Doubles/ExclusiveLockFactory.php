<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockFactoryInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockInterface;
use LogicException;

/**
 * Locks that refuse to be taken while held. A real lock would block the
 * second caller until the first lets go; inside one process that is waiting
 * on its own in-flight request, that means blocking forever. Throwing turns
 * the deadlock into a failure a test can see.
 */
final class ExclusiveLockFactory implements LockFactoryInterface
{
    /** @var array<string, true> */
    public array $held = [];

    public int $acquired = 0;

    /** @var list<string> */
    public array $keys = [];

    public function make(string $key): LockInterface
    {
        $this->keys[] = $key;

        return new class($this, $key) implements LockInterface {
            private bool $holding = false;

            public function __construct(
                private readonly ExclusiveLockFactory $factory,
                private readonly string $key,
            ) {
            }

            public function acquire(): void
            {
                if (isset($this->factory->held[$this->key])) {
                    throw new LogicException(sprintf('Lock "%s" is already held; a blocking lock would deadlock.', $this->key));
                }

                $this->factory->held[$this->key] = true;
                $this->factory->acquired++;
                $this->holding = true;
            }

            public function release(): void
            {
                if ($this->holding) {
                    unset($this->factory->held[$this->key]);
                    $this->holding = false;
                }
            }
        };
    }
}
