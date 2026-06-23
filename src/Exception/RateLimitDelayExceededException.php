<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Exception;

use RuntimeException;

final class RateLimitDelayExceededException extends RuntimeException
{
    public function __construct(public readonly float $retryAfter)
    {
        parent::__construct(sprintf('Required rate-limit delay %.2f seconds exceeds configured maximum.', $retryAfter));
    }
}
