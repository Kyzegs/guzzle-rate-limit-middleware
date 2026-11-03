<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

/**
 * Marks a bucket resolver whose route is safe to show: anything in the path that
 * could be a credential is replaced by a placeholder.
 *
 * Logs and exception messages name the route of such a resolver, and only the
 * method and host otherwise. A path can't be cleaned up generically, because a
 * token in it (Slack's incoming webhooks, for one) looks like any other word,
 * and a resolver that keeps words literal, as the default one does, would print it.
 */
interface LoggableRouteInterface
{
}
