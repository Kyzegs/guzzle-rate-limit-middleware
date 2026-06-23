<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\SleeperInterface;

/**
 * Records every requested sleep and advances an associated clock instead of
 * actually blocking, so timing behaviour can be asserted without real waits.
 */
final class RecordingSleeper implements SleeperInterface
{
    /** @var list<float> */
    public array $sleeps = [];

    public function __construct(private readonly ?FakeClock $clock = null)
    {
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->clock?->advance($seconds);
    }

    public function count(): int
    {
        return count($this->sleeps);
    }

    public function total(): float
    {
        return array_sum($this->sleeps);
    }
}
