<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Psr\Http\Message\ResponseInterface;

interface RetryAfterResolverInterface
{
    public function resolve(ResponseInterface $response, Headers $headers, ClockInterface $clock): float;
}
