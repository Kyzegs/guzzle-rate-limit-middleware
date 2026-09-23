<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

use Kyzegs\GuzzleRateLimitMiddleware\RateLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Reservation;

/**
 * A store that can take a bucket reservation in one atomic step.
 *
 * Any store works with a lock around a get-then-put, but that needs a lock
 * shared by every process, and the lock stays held for a whole round trip
 * while a bucket's allowance is unknown. A store implementing this decides and
 * records the reservation itself, as one indivisible operation, and the
 * handler takes no bucket lock at all.
 *
 * The rules are the ones the locked path applies to the
 * {@see RateLimit::toArray()} state that put() stores under the same key:
 *
 * - no state, or its window has passed: the first caller gets the probe and
 *   holds it until its response is persisted; everyone else waits for it;
 * - remaining above zero: take one, and the caller may send;
 * - otherwise: wait until the window resets.
 */
interface AtomicReservationStoreInterface extends StoreInterface
{
    /**
     * @param float $now The handler's clock, so the store and the handler agree
     *                   on whether a window has passed.
     */
    public function reserve(string $key, float $now): Reservation;
}
