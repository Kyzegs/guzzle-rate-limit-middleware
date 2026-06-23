<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

use Psr\Http\Message\RequestInterface;

/**
 * Implemented by bucket resolvers whose APIs subdivide a single rate-limit
 * bucket by "major" parameters (e.g. Discord channel/guild/webhook ids).
 *
 * When bucket-hash discovery is active, the discovered hash is combined with
 * these major parameters so that, for example, two different channels sharing
 * the same route hash still get independent rate-limit state.
 */
interface MajorParameterAwareInterface
{
    public function majorParameters(RequestInterface $request): string;
}
