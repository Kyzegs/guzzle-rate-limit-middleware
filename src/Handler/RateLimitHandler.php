<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Handler;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\AtomicReservationStoreInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\BucketResolverInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\ClockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\HandlerInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\IdentityResolverInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RetryAfterResolverInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockFactoryInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\MajorParameterAwareInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\SleeperInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\StoreInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\RateLimitExceededException;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\InvalidRequestLimitExceededException;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\RateLimitDelayExceededException;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\AuthorizationIdentityResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Support\DefaultRetryAfterResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Support\BucketKeyResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Support\HeaderParser;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Blocking rate-limit handler.
 *
 * Before each request it reads the persisted bucket state and, if the bucket is
 * exhausted, sleeps until the window resets. After each request it persists the
 * fresh state from the response headers. A 429 that slips through is retried
 * (honouring Retry-After) up to Options::$maxRetries, then optionally throws.
 *
 * Persisting through the injected {@see StoreInterface} is what makes rate
 * limiting work across separate requests/processes, not just within one call.
 */
final class RateLimitHandler implements HandlerInterface
{
    private readonly BucketKeyResolver $bucketKeys;

    public function __construct(
        private readonly Options $options,
        private readonly Headers $headers,
        private readonly StoreInterface $store,
        private readonly LoggerInterface $logger,
        private readonly BucketResolverInterface $resolver,
        private readonly LockFactoryInterface $lockFactory,
        private readonly ClockInterface $clock,
        private readonly SleeperInterface $sleeper,
        private readonly IdentityResolverInterface $identityResolver = new AuthorizationIdentityResolver(),
        private readonly RetryAfterResolverInterface $retryAfterResolver = new DefaultRetryAfterResolver(),
    ) {
        $this->bucketKeys = new BucketKeyResolver($this->store);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function handle(callable $handler, RequestInterface $request, array $options): mixed
    {
        $identity = $this->identityResolver->resolve($request);
        $this->enforceInvalidBudget($identity);
        $routeKey = $identity . ':' . $this->resolver->resolve($request);
        $major = $this->resolver instanceof MajorParameterAwareInterface
            ? $this->resolver->majorParameters($request)
            : '';
        $effectiveKey = $this->bucketKeys->effective($routeKey, $major);

        $tries = 0;

        while (true) {
            $probe = $this->reserveBucketCapacity($effectiveKey);

            try {
                $this->reserveGlobalCapacity($request, $identity);

                $promise = $handler($request, $options);
                $isPromise = $promise instanceof PromiseInterface;
                $response = $isPromise ? $promise->wait() : $promise;

                $effectiveKey = $this->persist($response, $routeKey, $major, $effectiveKey);
                $this->recordGlobalLimit($response, $identity);
                $this->recordInvalidRequest($response, $identity);
            } finally {
                $probe?->release();
            }

            if ($this->shouldRetry($response, $tries)) {
                $this->sleepForRetry($request, $response, ++$tries);

                continue;
            }

            $this->throwIfRateLimited($request, $response);

            return $isPromise ? Create::promiseFor($response) : $response;
        }
    }

    /**
     * Claim a slot in the bucket, sleeping until the window resets when it is
     * already spent.
     *
     * The lock covers the reservation only, never the request: the API allows
     * as many requests in flight as the bucket has remaining, so holding it
     * across the round trip would cap every bucket at one concurrent request
     * and make waiters time out under any fan-out.
     *
     * Returns the still-held lock when the allowance is unknown — nothing can
     * be reserved until a response reveals it, so that one request goes alone
     * and the caller releases the lock once it has landed.
     *
     * A store that can reserve atomically does all of this without the lock.
     */
    private function reserveBucketCapacity(string $effectiveKey): ?LockInterface
    {
        if ($this->store instanceof AtomicReservationStoreInterface) {
            return $this->reserveAtomically($this->store, $effectiveKey);
        }

        $slept = false;

        while (true) {
            $lock = $this->lockFactory->make($effectiveKey);
            $lock->acquire();

            $keepLock = false;

            try {
                $stored = $this->store->get($effectiveKey);
                $rateLimit = $stored === null ? null : RateLimit::fromArray($stored);

                // Unknown allowance, or one that has already been waited out:
                // only a response can reveal the new window, so go alone.
                if ($rateLimit === null || $slept || $rateLimit->secondsUntilReset($this->clock) <= 0.0) {
                    $keepLock = true;

                    return $lock;
                }

                if ($rateLimit->remaining > 0) {
                    $this->store->put(
                        $effectiveKey,
                        $rateLimit->withRemaining($rateLimit->remaining - 1)->toArray(),
                        $this->ttlFor($rateLimit),
                    );

                    return null;
                }

                $delay = $this->withMargin($rateLimit->secondsUntilReset($this->clock));
            } finally {
                if (! $keepLock) {
                    $lock->release();
                }
            }

            $this->logger->debug(sprintf(
                'Rate limit bucket "%s" exhausted; sleeping %.2fs until reset.',
                $effectiveKey,
                $delay,
            ));

            $this->sleeper->sleep($delay);
            $slept = true;
        }
    }

    /**
     * The same rules as the locked path, applied by a store that reads the
     * allowance and takes the slot in one indivisible step, so no bucket lock
     * is needed.
     *
     * A probe comes back as a handle that the caller releases once the
     * response has landed, exactly like the held lock. Waiting on another
     * caller's probe is a short poll rather than a window, so it gets no
     * safety margin; after a window wait the store sees the reset has passed
     * and hands out the probe, or a slot if someone else probed first.
     */
    private function reserveAtomically(AtomicReservationStoreInterface $store, string $effectiveKey): ?LockInterface
    {
        while (true) {
            $reservation = $store->reserve($effectiveKey, $this->clock->now());

            if ($reservation->isReserved()) {
                return null;
            }

            if ($reservation->probe !== null) {
                return $reservation->probe;
            }

            if ($reservation->probeInFlight) {
                $this->sleeper->sleep($reservation->delaySeconds);

                continue;
            }

            $delay = $this->withMargin($reservation->delaySeconds);

            $this->logger->debug(sprintf(
                'Rate limit bucket "%s" exhausted; sleeping %.2fs until reset.',
                $effectiveKey,
                $delay,
            ));

            $this->sleeper->sleep($delay);
        }
    }

    /**
     * Update the stored state from the response, re-keying via bucket-hash
     * discovery when the API reveals a bucket. Returns the (possibly new)
     * effective key.
     */
    private function persist(
        ResponseInterface $response,
        string $routeKey,
        string $major,
        string $effectiveKey,
    ): string {
        $rateLimit = RateLimit::fromResponse($response, $this->headers, $this->clock);

        if ($this->headers->needsToCheckBucketHeader() && $rateLimit->bucketHash !== null) {
            $effectiveKey = $this->bucketKeys->observe($routeKey, $rateLimit->bucketHash, $major);
        }

        $rateLimit = $this->lowestSeen($effectiveKey, $rateLimit);

        $this->store->put($effectiveKey, $rateLimit->toArray(), $this->ttlFor($rateLimit));

        return $effectiveKey;
    }

    /**
     * With requests in flight concurrently their responses can land out of
     * order, and a later one carrying a higher `remaining` would hand back
     * capacity that has already been spent. Keep the lowest count seen while
     * the window holds.
     *
     * Same-window responses agree on the reset instant to within network
     * jitter, a new window moves it by the whole window length; anything
     * within half a second is treated as the same window.
     */
    private function lowestSeen(string $effectiveKey, RateLimit $fresh): RateLimit
    {
        $stored = $this->store->get($effectiveKey);

        if ($stored === null) {
            return $fresh;
        }

        $previous = RateLimit::fromArray($stored);

        if ($fresh->reset - $previous->reset >= 0.5 || $previous->secondsUntilReset($this->clock) <= 0.0) {
            return $fresh;
        }

        return $fresh->remaining <= $previous->remaining
            ? $fresh
            : $fresh->withRemaining($previous->remaining);
    }

    private function shouldRetry(ResponseInterface $response, int $tries): bool
    {
        return $tries < $this->options->maxRetries
            && in_array($response->getStatusCode(), $this->options->retryStatusCodes, true);
    }

    private function sleepForRetry(RequestInterface $request, ResponseInterface $response, int $tries): void
    {
        $delay = $this->withMargin($this->retryAfter($response));
        $global = $this->isGlobal($response);

        $this->logger->warning(sprintf(
            'Received %d for %s %s%s. Retry %d/%d in %.2fs.',
            $response->getStatusCode(),
            $request->getMethod(),
            (string) $request->getUri(),
            $global ? ' (global rate limit)' : '',
            $tries,
            $this->options->maxRetries,
            $delay,
        ));

        $this->sleeper->sleep($delay);
    }

    private function throwIfRateLimited(RequestInterface $request, ResponseInterface $response): void
    {
        if ($response->getStatusCode() !== 429 || ! $this->options->throwOnRateLimit) {
            return;
        }

        throw new RateLimitExceededException(
            $request,
            $response,
            $this->retryAfter($response),
            $this->isGlobal($response),
        );
    }

    /**
     * Best-effort retry delay: prefer Retry-After, fall back to the bucket reset.
     */
    private function retryAfter(ResponseInterface $response): float
    {
        return $this->retryAfterResolver->resolve($response, $this->headers, $this->clock);
    }

    private function isGlobal(ResponseInterface $response): bool
    {
        $global = HeaderParser::value($response, $this->headers->global);
        if ($global !== null && strtolower((string) $global) === 'true') {
            return true;
        }

        $scope = HeaderParser::value($response, $this->headers->scope);

        if ($scope === null) {
            $body = $response->getBody();
            $position = $body->isSeekable() ? $body->tell() : null;
            $decoded = json_decode((string) $body, true);
            if ($position !== null) {
                $body->seek($position);
            }
            if (is_array($decoded) && ($decoded['global'] ?? false) === true) {
                return true;
            }
        }

        return $scope !== null && strtolower((string) $scope) === 'global';
    }

    private function withMargin(float $delay): float
    {
        $delay += $this->options->safetyBufferSeconds;

        if ($this->options->jitterPercent > 0.0) {
            $delay += $delay * ($this->options->jitterPercent / 100.0) * (mt_rand() / mt_getrandmax());
        }

        if ($this->options->maxDelaySeconds !== null && $delay > $this->options->maxDelaySeconds) {
            throw new RateLimitDelayExceededException($delay);
        }

        return $delay;
    }

    private function reserveGlobalCapacity(RequestInterface $request, string $identity): void
    {
        $config = $this->options->globalLimit;
        if ($config === null || $this->isInteractionCallback($request)) {
            return;
        }

        $key = 'global:' . hash('sha256', $identity);
        while (true) {
            $lock = $this->lockFactory->make($key);
            $lock->acquire();
            $delay = 0.0;
            try {
                $now = $this->clock->now();
                $state = $this->store->get($key) ?? [];
                $reset = (float) ($state['reset'] ?? 0.0);
                $blockedUntil = (float) ($state['blocked_until'] ?? 0.0);
                if ($blockedUntil > $now) {
                    $delay = $blockedUntil - $now;
                } else {
                    $count = $reset > $now ? (int) ($state['count'] ?? 0) : 0;
                    $reset = $reset > $now ? $reset : $now + $config->windowSeconds;
                    if ($count >= $config->maxRequests) {
                        $delay = $reset - $now;
                    } else {
                        $this->store->put($key, ['count' => $count + 1, 'reset' => $reset], (int) ceil($config->windowSeconds) + 1);
                        return;
                    }
                }
            } finally {
                $lock->release();
            }
            $this->sleeper->sleep($this->withMargin($delay));
        }
    }

    private function recordGlobalLimit(ResponseInterface $response, string $identity): void
    {
        if (! $this->isGlobal($response)) {
            return;
        }
        $delay = $this->retryAfter($response);
        $key = 'global:' . hash('sha256', $identity);
        $this->store->put($key, ['count' => PHP_INT_MAX, 'reset' => $this->clock->now() + $delay, 'blocked_until' => $this->clock->now() + $delay], (int) ceil($delay) + 1);
    }

    private function enforceInvalidBudget(string $identity): void
    {
        $config = $this->options->invalidRequestLimit;
        if ($config === null) {
            return;
        }
        $state = $this->store->get('invalid:' . hash('sha256', $identity));
        if ($state !== null && (int) ($state['count'] ?? 0) >= $config->maxRequests && (float) ($state['reset'] ?? 0.0) > $this->clock->now()) {
            throw new InvalidRequestLimitExceededException((float) $state['reset'] - $this->clock->now());
        }
    }

    private function recordInvalidRequest(ResponseInterface $response, string $identity): void
    {
        $config = $this->options->invalidRequestLimit;
        $status = $response->getStatusCode();
        if ($config === null || ! in_array($status, $config->statusCodes, true) || ($status === 429 && $this->scope($response) === 'shared')) {
            return;
        }
        $key = 'invalid:' . hash('sha256', $identity);
        $lock = $this->lockFactory->make($key);
        $lock->acquire();
        try {
            $now = $this->clock->now();
            $state = $this->store->get($key) ?? [];
            $reset = (float) ($state['reset'] ?? 0.0);
            $count = $reset > $now ? (int) ($state['count'] ?? 0) : 0;
            $reset = $reset > $now ? $reset : $now + $config->windowSeconds;
            $this->store->put($key, ['count' => $count + 1, 'reset' => $reset], (int) ceil($config->windowSeconds) + 1);
        } finally {
            $lock->release();
        }
    }

    private function scope(ResponseInterface $response): string
    {
        return strtolower((string) HeaderParser::value($response, $this->headers->scope, 'user'));
    }

    private function isInteractionCallback(RequestInterface $request): bool
    {
        return preg_match('#/interactions/[^/]+/[^/]+/callback$#', $request->getUri()->getPath()) === 1;
    }

    private function ttlFor(RateLimit $rateLimit): int
    {
        $seconds = (int) ceil($rateLimit->secondsUntilReset($this->clock))
            + (int) ceil($this->options->safetyBufferSeconds);

        return min(max($seconds, 60), $this->options->maxStoreTtl);
    }
}
