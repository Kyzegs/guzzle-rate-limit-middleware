<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

use Psr\Http\Message\RequestInterface;

interface HandlerInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function handle(callable $handler, RequestInterface $request, array $options): mixed;
}
