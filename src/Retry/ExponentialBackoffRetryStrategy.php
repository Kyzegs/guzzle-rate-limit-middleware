<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Retry;

use Closure;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RetryStrategyInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Back off exponentially when a response is retried without saying how long
 * to wait.
 *
 * Without Retry-After or a reset header the inner strategy has nothing to go
 * on and asks for a zero wait, which leaves only the safety buffer: a retry
 * about every second for as long as the retries last. This waits
 * base * 2^(attempt - 1) seconds instead, capped at $maxSeconds, with full
 * jitter (a random wait between zero and that) so that clients which failed
 * together don't all come back together.
 *
 * The inner strategy still decides whether to retry, and a wait it chose
 * itself (from a header, or its own rules) is left alone.
 */
final class ExponentialBackoffRetryStrategy implements RetryStrategyInterface
{
    /** @var Closure(): float */
    private readonly Closure $random;

    /**
     * @param float                   $baseSeconds Wait ceiling for the first retry, doubled for each one after.
     * @param float                   $maxSeconds  Upper bound for the wait ceiling.
     * @param (Closure(): float)|null $random      Returns a float in [0, 1]; injectable so tests are deterministic.
     */
    public function __construct(
        private readonly RetryStrategyInterface $inner,
        private readonly float $baseSeconds = 1.0,
        private readonly float $maxSeconds = 60.0,
        ?Closure $random = null,
    ) {
        $this->random = $random ?? static fn (): float => mt_rand() / mt_getrandmax();
    }

    public function retryDelay(
        RequestInterface $request,
        ResponseInterface $response,
        int $attempt,
        float $hintedDelay,
    ): ?float {
        $delay = $this->inner->retryDelay($request, $response, $attempt, $hintedDelay);

        if ($delay === null || $delay > 0.0) {
            return $delay;
        }

        $ceiling = min($this->maxSeconds, $this->baseSeconds * 2 ** max(0, $attempt - 1));

        return $ceiling * ($this->random)();
    }

    public function isRateLimited(ResponseInterface $response): bool
    {
        return $this->inner->isRateLimited($response);
    }
}
