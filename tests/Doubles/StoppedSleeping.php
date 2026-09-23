<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles;

use RuntimeException;

/**
 * Thrown by {@see StoppingSleeper} to unwind a caller that decided to wait.
 */
final class StoppedSleeping extends RuntimeException
{
}
