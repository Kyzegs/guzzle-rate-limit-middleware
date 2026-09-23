<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Config;

/**
 * How a client-side {@see Limit} counts requests against its window.
 *
 * They differ in how much burst they let through around a window edge, which
 * matters when the budget mirrors a provider limit you must not cross:
 *
 * - FixedWindow allows maxRequests per window, the window starting with the
 *   first request after the previous one ended. Cheapest and easiest to reason
 *   about, but a full window at the end of one period followed by another at
 *   the start of the next puts up to twice the budget into one window length.
 * - SlidingWindow weights the previous window's count by how much of it still
 *   overlaps a window ending now (the two-window counter approximation). It
 *   smooths the edge burst away at the cost of assuming the previous window's
 *   requests were spread evenly.
 * - TokenBucket holds up to maxRequests tokens and refills maxRequests per
 *   windowSeconds continuously. It allows a full burst after an idle period,
 *   then paces requests evenly at the refill rate.
 */
enum LimitAlgorithm: string
{
    case FixedWindow = 'fixed_window';
    case SlidingWindow = 'sliding_window';
    case TokenBucket = 'token_bucket';
}
