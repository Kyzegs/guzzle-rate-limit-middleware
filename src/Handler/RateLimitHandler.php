<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Handler;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
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
use Kyzegs\GuzzleRateLimitMiddleware\Support\JsonBody;
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
    /**
     * How long a route that answered without rate-limit headers is treated as
     * unmetered before a request probes it again.
     */
    private const UNMETERED_TTL = 60;

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
        $effectiveKey = $this->bucketKeys->effective($routeKey, $major, $identity);

        $tries = 0;

        while (true) {
            $probe = $this->reserveBucketCapacity($effectiveKey);

            try {
                $this->reserveGlobalCapacity($request, $identity);

                $promise = $handler($request, $options);
                $isPromise = $promise instanceof PromiseInterface;
                $response = $isPromise ? $promise->wait() : $promise;

                $effectiveKey = $this->persist($response, $routeKey, $major, $identity, $effectiveKey, $probe !== null ? $effectiveKey : null);
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
     */
    private function reserveBucketCapacity(string $effectiveKey): ?LockInterface
    {
        $slept = false;

        while (true) {
            $lock = $this->lockFactory->make($effectiveKey);
            $lock->acquire();

            $keepLock = false;

            try {
                $stored = $this->store->get($effectiveKey);

                // The API doesn't meter this route: nothing to reserve.
                if (($stored['unmetered'] ?? false) === true) {
                    return null;
                }

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
     * Update the stored state from the response, re-keying via bucket-hash
     * discovery when the API reveals a bucket. Returns the (possibly new)
     * effective key.
     *
     * Both writes below read the stored window first, so they run under the
     * bucket's lock: a reservation landing between the read and the write
     * would otherwise be overwritten, handing a spent slot back. A probe
     * already holds the lock for the key it reserved, and taking it a second
     * time would deadlock a per-handle lock such as flock, so $heldKey names
     * the key whose lock this request already has.
     */
    private function persist(
        ResponseInterface $response,
        string $routeKey,
        string $major,
        string $identity,
        string $effectiveKey,
        ?string $heldKey,
    ): string {
        if (! $this->headers->presentIn($response)) {
            $this->underBucketLock($effectiveKey, $heldKey, fn () => $this->recordUnmetered($response, $effectiveKey));

            return $effectiveKey;
        }

        $rateLimit = RateLimit::fromResponse($response, $this->headers, $this->clock);

        if ($this->headers->needsToCheckBucketHeader() && $rateLimit->bucketHash !== null) {
            $effectiveKey = $this->bucketKeys->observe($routeKey, $rateLimit->bucketHash, $major, $identity);
        }

        $this->underBucketLock($effectiveKey, $heldKey, function () use ($effectiveKey, $rateLimit): void {
            $merged = $this->lowestSeen($effectiveKey, $rateLimit);

            $this->store->put($effectiveKey, $merged->toArray(), $this->ttlFor($merged));
        });

        return $effectiveKey;
    }

    private function underBucketLock(string $key, ?string $heldKey, callable $write): void
    {
        $lock = $key === $heldKey ? null : $this->lockFactory->make($key);
        $lock?->acquire();

        try {
            $write();
        } finally {
            $lock?->release();
        }
    }

    /**
     * A response without rate-limit headers says nothing about the bucket, so
     * it must not overwrite a known window: an error page from a proxy would
     * otherwise hand back capacity that is already spent.
     *
     * A successful one on a route with no live window means the API doesn't
     * meter it. Remember that for a while; left unknown, every request on the
     * route would go alone as a probe and hold the lock across its round trip.
     */
    private function recordUnmetered(ResponseInterface $response, string $effectiveKey): void
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 400) {
            return;
        }

        $stored = $this->store->get($effectiveKey);
        if ($stored !== null && RateLimit::fromArray($stored)->secondsUntilReset($this->clock) > 0.0) {
            return;
        }

        $this->store->put($effectiveKey, ['unmetered' => true], self::UNMETERED_TTL);
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
        if ($scope !== null) {
            return strtolower((string) $scope) === 'global';
        }

        // Only a 429 describes a rate limit in its body. On any other response
        // the body is the API's own payload, where a "global" field means
        // something else entirely.
        return $response->getStatusCode() === 429
            && (JsonBody::decode($response)['global'] ?? false) === true;
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
        if ($response->getStatusCode() !== 429 || ! $this->isGlobal($response)) {
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
