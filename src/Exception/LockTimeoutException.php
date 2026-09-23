<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Exception;

use RuntimeException;

/**
 * Thrown when a rate-limit lock could not be acquired within its timeout.
 */
final class LockTimeoutException extends RuntimeException
{
    public function __construct(public readonly string $key, public readonly float $timeoutSeconds)
    {
        parent::__construct(sprintf('Could not acquire rate-limit lock "%s" within %.2f seconds.', $key, $timeoutSeconds));
    }
}
