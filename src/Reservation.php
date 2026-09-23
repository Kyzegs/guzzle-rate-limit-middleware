<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\AtomicReservationStoreInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockInterface;

/**
 * What an atomic bucket reservation decided for one caller.
 *
 * Returned by {@see AtomicReservationStoreInterface::reserve()}: the caller
 * either got a slot, is the one caller allowed to probe an unknown bucket, or
 * has to wait and ask again.
 */
final class Reservation
{
    private const RESERVED = 'reserved';

    private const PROBE = 'probe';

    private const WAIT = 'wait';

    /**
     * @param self::RESERVED|self::PROBE|self::WAIT $outcome
     * @param LockInterface|null $probe         Held for a probe; release it once the response is persisted.
     * @param float              $delaySeconds  How long to wait before asking again.
     * @param bool               $probeInFlight Whether the wait is for another caller's probe rather than a window reset.
     */
    private function __construct(
        private readonly string $outcome,
        public readonly ?LockInterface $probe = null,
        public readonly float $delaySeconds = 0.0,
        public readonly bool $probeInFlight = false,
    ) {
    }

    /**
     * A slot was taken out of the bucket's remaining allowance; send now.
     */
    public static function reserved(): self
    {
        return new self(self::RESERVED);
    }

    /**
     * The allowance is unknown, or its window has passed, and only a response
     * can reveal the new one: this caller alone may send. Everyone else waits
     * until $probe is released, so release it once the response has been
     * persisted, or the request has failed.
     */
    public static function probe(LockInterface $probe): self
    {
        return new self(self::PROBE, probe: $probe);
    }

    /**
     * The bucket is spent until its window resets in $seconds.
     */
    public static function wait(float $seconds): self
    {
        return new self(self::WAIT, delaySeconds: max(0.0, $seconds));
    }

    /**
     * Another caller is probing the bucket; ask again in $seconds. This is a
     * poll, not a window, so it needs no margin for clock skew.
     */
    public static function waitForProbe(float $seconds): self
    {
        return new self(self::WAIT, delaySeconds: max(0.0, $seconds), probeInFlight: true);
    }

    public function isReserved(): bool
    {
        return $this->outcome === self::RESERVED;
    }

    public function isProbe(): bool
    {
        return $this->outcome === self::PROBE;
    }

    public function isWait(): bool
    {
        return $this->outcome === self::WAIT;
    }
}
