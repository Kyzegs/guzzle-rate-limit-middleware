<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Config;

/**
 * Which requests share a client-side {@see Limit}'s budget.
 *
 * The scopes nest: every scope but Everything is isolated per identity (the
 * authorization fingerprint), because providers almost always count per
 * credential and one token's traffic should never slow another's down.
 *
 * - Everything: one budget for every request the middleware sees, whatever
 *   its credentials. Combine with Limit::$appliesTo for a host-wide budget
 *   shared by several tokens, e.g. a per-IP limit.
 * - Identity: one budget per identity, across all hosts and routes.
 * - Host: one budget per identity per host.
 * - Route: one budget per identity per route, as the configured bucket
 *   resolver groups them.
 */
enum LimitScope: string
{
    case Identity = 'identity';
    case Host = 'host';
    case Route = 'route';
    case Everything = 'everything';
}
