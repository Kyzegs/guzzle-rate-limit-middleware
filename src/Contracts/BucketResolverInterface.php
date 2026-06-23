<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

use Psr\Http\Message\RequestInterface;

interface BucketResolverInterface
{
    public function resolve(RequestInterface $request): string;
}