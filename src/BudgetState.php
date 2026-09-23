<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware;

/**
 * Immutable snapshot of one of the budgets the middleware imposes on itself:
 * the {@see Config\GlobalLimit} or {@see Config\InvalidRequestLimit} counter
 * of a single identity.
 *
 * Unlike a {@see RateLimit}, nothing here is learned from the API, so the
 * allowance is known before the first request: an untouched budget is simply
 * one with no window open yet.
 */
final class BudgetState
{
    /**
     * @param int        $count        Requests counted in the current window: 0 when none is open, $max while blocked.
     * @param int        $max          Requests allowed per window.
     * @param float|null $reset        Absolute UNIX timestamp at which the window closes; null when none is open.
     * @param float|null $blockedUntil Absolute UNIX timestamp until which a global 429 holds every request back.
     */
    public function __construct(
        public readonly int $count,
        public readonly int $max,
        public readonly ?float $reset = null,
        public readonly ?float $blockedUntil = null,
    ) {
    }

    /**
     * Requests that can still be sent before the window closes.
     */
    public function remaining(): int
    {
        return $this->blockedUntil !== null ? 0 : max(0, $this->max - $this->count);
    }
}
