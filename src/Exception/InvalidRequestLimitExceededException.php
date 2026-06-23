<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Exception;

use RuntimeException;

final class InvalidRequestLimitExceededException extends RuntimeException
{
    public function __construct(public readonly float $retryAfter)
    {
        parent::__construct(sprintf('Invalid request safety limit reached; retry in %.2f seconds.', $retryAfter));
    }
}
