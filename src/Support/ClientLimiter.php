<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Support;

use Closure;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Limit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\LimitAlgorithm;
use Kyzegs\GuzzleRateLimitMiddleware\Config\LimitScope;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\ClockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockFactoryInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\SleeperInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\StoreInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Enforces the client-side {@see Limit}s configured in Options::$limits.
 *
 * A request must fit every limit that applies to it. All of them are checked
 * first and consumed only once each has room, so a request waiting out its
 * hourly budget never sits on a slot of its per-second one: that slot would be
 * spent on nothing while it sleeps, and requests that only share the
 * per-second limit would be held back for no reason. When any limit is spent
 * the caller sleeps for the longest wait among them and checks again, since
 * other processes may have used the capacity in the meantime.
 *
 * The locks cover the check-and-consume, never the sleep or the request, and
 * are taken in sorted key order so two callers sharing several limits can
 * never each hold one while waiting on the other.
 *
 * @internal Built by RateLimitHandler, which supplies its delay margin.
 */
final class ClientLimiter
{
    /**
     * A wait this short (one microsecond) counts as none. Float timestamps
     * are only precise to a fraction of that, so a sleep computed to land
     * exactly on a threshold may wake a hair before it; without the slack
     * the caller would be sent back for a second, margin-padded sleep.
     */
    private const TOLERANCE = 1e-6;

    /**
     * @param list<Limit>           $limits
     * @param Closure(float): float $margin Turns a required delay into the one to sleep (safety buffer, jitter, maximum delay).
     */
    public function __construct(
        private readonly array $limits,
        private readonly StoreInterface $store,
        private readonly LockFactoryInterface $lockFactory,
        private readonly ClockInterface $clock,
        private readonly SleeperInterface $sleeper,
        private readonly Closure $margin,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Block until every limit that applies to the request has room, then
     * take one slot from each of them.
     */
    public function reserve(RequestInterface $request, string $identity, string $routeKey, ?Closure $margin = null): void
    {
        $limits = $this->applicable($request, $identity, $routeKey);

        if ($limits === []) {
            return;
        }

        while (true) {
            /** @var list<LockInterface> $locks */
            $locks = [];

            try {
                foreach (array_keys($limits) as $key) {
                    $lock = $this->lockFactory->make($key);
                    $lock->acquire();
                    $locks[] = $lock;
                }

                $now = $this->clock->now();
                $delay = 0.0;
                $blocking = null;
                $claims = [];

                foreach ($limits as $key => $limit) {
                    $claim = $this->check($limit, $this->store->get($key), $now);

                    if ($claim['delay'] > self::TOLERANCE && $claim['delay'] > $delay) {
                        $delay = $claim['delay'];
                        $blocking = $limit;
                    }

                    $claims[$key] = $claim;
                }

                if ($blocking === null) {
                    foreach ($claims as $key => $claim) {
                        $this->store->put($key, $claim['state'], (int) ceil(max(0.0, $claim['expires'] - $now)) + 1);
                    }

                    return;
                }
            } finally {
                foreach (array_reverse($locks) as $lock) {
                    $lock->release();
                }
            }

            $delay = ($margin ?? $this->margin)($delay);

            $this->logger->debug(sprintf(
                'Client-side limit %s reached; sleeping %.2fs before checking again.',
                $this->describe($blocking),
                $delay,
            ));

            $this->sleeper->sleep($delay);
        }
    }

    /**
     * The limits that apply to the request, by storage key in lock order.
     *
     * Limits with the same settings and name resolve to the same key and are
     * one budget: consuming it twice per request would halve it, and locking
     * it twice would deadlock on a non-reentrant lock.
     *
     * @return array<string, Limit>
     */
    private function applicable(RequestInterface $request, string $identity, string $routeKey): array
    {
        $limits = [];

        foreach ($this->limits as $limit) {
            if ($limit->matches($request)) {
                $limits[$this->key($limit, $request, $identity, $routeKey)] = $limit;
            }
        }

        ksort($limits, SORT_STRING);

        return $limits;
    }

    /**
     * Storage (and lock) key for a limit as it applies to this request. The
     * scope's identity, host and route are hashed along with the limit's
     * settings, so no credential fingerprint, hostname or path is persisted.
     */
    private function key(Limit $limit, RequestInterface $request, string $identity, string $routeKey): string
    {
        $scope = match ($limit->scope) {
            LimitScope::Everything => '',
            LimitScope::Identity => $identity,
            LimitScope::Host => $identity . "\0" . strtolower($request->getUri()->getHost()),
            LimitScope::Route => $routeKey,
        };

        return 'limit:' . hash('sha256', implode("\0", [
            $limit->name,
            $limit->algorithm->value,
            $limit->maxRequests,
            $limit->windowSeconds,
            $limit->scope->value,
            $scope,
        ]));
    }

    /**
     * How long the request must wait for this limit (zero or less when it
     * fits now), the state after it takes its slot, and when that state
     * stops mattering. Admission and waiting come from the same formula, so
     * a caller that sleeps for the delay finds room when it wakes.
     *
     * @param array<string, mixed>|null $state
     * @return array{delay: float, state: array<string, float|int>, expires: float}
     */
    private function check(Limit $limit, ?array $state, float $now): array
    {
        return match ($limit->algorithm) {
            LimitAlgorithm::FixedWindow => $this->fixedWindow($limit, $state, $now),
            LimitAlgorithm::SlidingWindow => $this->slidingWindow($limit, $state, $now),
            LimitAlgorithm::TokenBucket => $this->tokenBucket($limit, $state, $now),
        };
    }

    /**
     * A counter over a window that opens with the first request after the
     * previous one closed, the same shape as the global budget.
     *
     * @param array<string, mixed>|null $state
     * @return array{delay: float, state: array<string, float|int>, expires: float}
     */
    private function fixedWindow(Limit $limit, ?array $state, float $now): array
    {
        $reset = (float) ($state['reset'] ?? 0.0);
        $open = $reset - $now > self::TOLERANCE;
        $count = $open ? (int) ($state['count'] ?? 0) : 0;
        $reset = $open ? $reset : $now + $limit->windowSeconds;

        return [
            'delay' => $count >= $limit->maxRequests ? $reset - $now : 0.0,
            'state' => ['count' => $count + 1, 'reset' => $reset],
            'expires' => $reset,
        ];
    }

    /**
     * Two-window counter: the previous window's requests count in proportion
     * to how much of it still overlaps a window ending now, and a request
     * fits while that estimate plus the current count is within the budget.
     * Windows are anchored at the first request and advance by whole
     * lengths, so the previous window always sits directly before the
     * current one; after a longer gap it has nothing left to contribute.
     *
     * @param array<string, mixed>|null $state
     * @return array{delay: float, state: array<string, float|int>, expires: float}
     */
    private function slidingWindow(Limit $limit, ?array $state, float $now): array
    {
        $window = $limit->windowSeconds;
        $max = $limit->maxRequests;
        $start = (float) ($state['start'] ?? $now);
        $current = (int) ($state['current'] ?? 0);
        $previous = (int) ($state['previous'] ?? 0);

        if ($now - $start > $window - self::TOLERANCE) {
            $passed = floor(($now - $start + self::TOLERANCE) / $window);
            $previous = $passed === 1.0 ? $current : 0;
            $current = 0;
            $start += $passed * $window;
        }

        // Clamped against rounding in the roll-over above, and against a
        // process whose clock runs slightly behind the one that anchored it.
        $elapsed = min(max(0.0, $now - $start), $window);

        $delay = match (true) {
            // This window is full on its own: wait for the next one, where
            // its count becomes the previous share and starts sliding out.
            $current >= $max => $window - $elapsed,
            $previous === 0 => 0.0,
            // Wait until previous * (1 - elapsed / window) + current <= max.
            default => $window * (1.0 - ($max - $current) / $previous) - $elapsed,
        };

        return [
            'delay' => $delay,
            'state' => ['start' => $start, 'previous' => $previous, 'current' => $current + 1],
            'expires' => $start + 2 * $window,
        ];
    }

    /**
     * Holds up to maxRequests tokens, refilled continuously at maxRequests
     * per window. Only the count at the last claim is stored; the refill
     * since is worked out on read, so nothing has to tick.
     *
     * @param array<string, mixed>|null $state
     * @return array{delay: float, state: array<string, float|int>, expires: float}
     */
    private function tokenBucket(Limit $limit, ?array $state, float $now): array
    {
        $capacity = (float) $limit->maxRequests;
        $rate = $capacity / $limit->windowSeconds;

        $tokens = $state === null
            ? $capacity
            : min($capacity, (float) ($state['tokens'] ?? $capacity) + max(0.0, $now - (float) ($state['updated'] ?? $now)) * $rate);
        $left = max(0.0, $tokens - 1.0);

        return [
            'delay' => $tokens >= 1.0 ? 0.0 : (1.0 - $tokens) / $rate,
            'state' => ['tokens' => $left, 'updated' => $now],
            // Once full again the bucket is indistinguishable from no state.
            'expires' => $now + ($capacity - $left) / $rate,
        ];
    }

    private function describe(Limit $limit): string
    {
        return $limit->name !== ''
            ? sprintf('"%s"', $limit->name)
            : sprintf('%d per %gs (%s, %s)', $limit->maxRequests, $limit->windowSeconds, $limit->algorithm->value, $limit->scope->value);
    }
}
