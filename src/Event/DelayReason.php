<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Event;

/**
 * Why a request was held back before being sent.
 *
 * String-backed so the value can go straight into a metric label or a log
 * context without a lookup table.
 */
enum DelayReason: string
{
    /** The request's own bucket had no remaining allowance in this window. */
    case Bucket = 'bucket';

    /** The identity-wide request budget, or a global 429 cooldown, was in force. */
    case GlobalLimit = 'global';
}
