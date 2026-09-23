<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockFactoryInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockInterface;
use LogicException;

/**
 * Tracks which keys are locked right now. Like flock, a second acquire of a
 * key that is already held would never return, so it throws instead.
 */
final class TrackingLockFactory implements LockFactoryInterface
{
    /** @var array<string, true> */
    public array $held = [];

    public function make(string $key): LockInterface
    {
        return new class($this, $key) implements LockInterface {
            private bool $acquired = false;

            public function __construct(private readonly TrackingLockFactory $factory, private readonly string $key)
            {
            }

            public function acquire(): void
            {
                if (isset($this->factory->held[$this->key])) {
                    throw new LogicException(sprintf('Lock "%s" is already held; a real lock would deadlock.', $this->key));
                }

                $this->factory->held[$this->key] = true;
                $this->acquired = true;
            }

            public function release(): void
            {
                if ($this->acquired) {
                    unset($this->factory->held[$this->key]);
                    $this->acquired = false;
                }
            }
        };
    }
}
