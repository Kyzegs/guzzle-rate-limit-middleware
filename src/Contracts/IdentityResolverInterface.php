<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

use Psr\Http\Message\RequestInterface;

interface IdentityResolverInterface
{
    public function resolve(RequestInterface $request): string;
}
