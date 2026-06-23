<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Support;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function now(): float
    {
        return microtime(true);
    }
}
