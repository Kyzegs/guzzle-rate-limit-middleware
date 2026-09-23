<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles;

use Closure;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\SleeperInterface;

/**
 * Records sleeps like {@see RecordingSleeper}, and can run a callback once,
 * mid-sleep and before the clock moves: what another caller would do while
 * this one is waiting.
 */
final class HookedSleeper implements SleeperInterface
{
    /** @var list<float> */
    public array $sleeps = [];

    /** @var (Closure(): void)|null */
    public ?Closure $whileAsleep = null;

    public function __construct(private readonly FakeClock $clock)
    {
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;

        $hook = $this->whileAsleep;
        $this->whileAsleep = null;
        if ($hook !== null) {
            $hook();
        }

        $this->clock->advance($seconds);
    }
}
