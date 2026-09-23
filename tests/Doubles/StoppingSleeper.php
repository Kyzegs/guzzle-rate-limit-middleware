<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\SleeperInterface;

/**
 * Records the first sleep it is asked for and then stops the caller, so a test
 * can see a caller decide to wait without waiting for real, or forever.
 */
final class StoppingSleeper implements SleeperInterface
{
    /** @var list<float> */
    public array $sleeps = [];

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;

        throw new StoppedSleeping(sprintf('Asked to sleep %.3fs.', $seconds));
    }
}
