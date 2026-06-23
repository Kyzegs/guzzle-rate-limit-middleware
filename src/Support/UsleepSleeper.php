<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Support;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\SleeperInterface;

final class UsleepSleeper implements SleeperInterface
{
    public function sleep(float $seconds): void
    {
        if ($seconds <= 0.0) {
            return;
        }

        usleep((int) round($seconds * 1_000_000));
    }
}
