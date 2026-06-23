<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Resolver;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\IdentityResolverInterface;
use Psr\Http\Message\RequestInterface;

final class AuthorizationIdentityResolver implements IdentityResolverInterface
{
    public function resolve(RequestInterface $request): string
    {
        $authorization = trim($request->getHeaderLine('Authorization'));

        return $authorization === ''
            ? 'unauthenticated'
            : hash('sha256', $authorization);
    }
}
