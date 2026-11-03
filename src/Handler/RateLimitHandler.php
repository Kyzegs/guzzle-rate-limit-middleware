<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Handler;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Kyzegs\GuzzleRateLimitMiddleware\BudgetState;
use Kyzegs\GuzzleRateLimitMiddleware\Config\GlobalLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\Config\InvalidRequestLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\Config\RequestOptions;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\AtomicReservationStoreInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\BucketResolverInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\ClockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\IdentityResolverInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\InspectableHandlerInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RetryAfterResolverInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockFactoryInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\MajorParameterAwareInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RateLimitParserInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RetryStrategyInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\SleeperInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\StoreInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Event\DelayReason;
use Kyzegs\GuzzleRateLimitMiddleware\Event\InvalidRequestLimitReached;
use Kyzegs\GuzzleRateLimitMiddleware\Event\RateLimitExceeded;
use Kyzegs\GuzzleRateLimitMiddleware\Event\RateLimitUpdated;
use Kyzegs\GuzzleRateLimitMiddleware\Event\RequestDelayed;
use Kyzegs\GuzzleRateLimitMiddleware\Event\RetryScheduled;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\RateLimitExceededException;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\InvalidRequestLimitExceededException;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\RateLimitDelayExceededException;
use Kyzegs\GuzzleRateLimitMiddleware\Parser\HeaderRateLimitParser;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\AuthorizationIdentityResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Retry\StatusCodeRetryStrategy;
use Kyzegs\GuzzleRateLimitMiddleware\Support\DefaultRetryAfterResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Support\BucketKeyResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Support\ClientLimiter;
use Kyzegs\GuzzleRateLimitMiddleware\Support\HeaderParser;
use Kyzegs\GuzzleRateLimitMiddleware\Support\JsonBody;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Promise-based rate-limit handler.
 *
 * Before each request it reads the persisted bucket state and, if the bucket is
 * exhausted, sleeps until the window resets. Once the response lands it
 * persists the fresh state the {@see RateLimitParserInterface} reads from the
 * response. A rate limit that slips through is retried as the {@see RetryStrategyInterface} decides (by default:
 * a 429, honouring Retry-After, up to Options::$maxRetries times), then
 * optionally rejects with RateLimitExceededException.
 *
 * The transfer itself is never waited on: the bookkeeping and any retry are
 * chained onto the inner handler's promise, so getAsync(), Pool and
 * Promise\Utils::all() keep their requests in flight together. The waits are
 * another matter. An exhausted bucket, a spent global budget and a retry
 * delay still block through the {@see SleeperInterface}, because each must
 * re-read the shared state once the time has passed, and without an event
 * loop there is nothing to hang that re-read on. Transfers run concurrently;
 * waiting for an exhausted bucket still blocks.
 *
 * A request can adjust or bypass all of this through the "rate_limit" request
 * option; see {@see RequestOptions}.
 *
 * Persisting through the injected {@see StoreInterface} is what makes rate
 * limiting work across separate requests/processes, not just within one call.
 *
 * Client-side limits (Options::$limits) are enforced alongside, through the
 * same store, locks and delay margin, whether or not the API sends headers.
 *
 * Every wait, retry and state change is announced to the optional PSR-14
 * dispatcher (see the Event namespace) and logged with structured context, so
 * callers can count and export them without parsing log lines.
 */
final class RateLimitHandler implements InspectableHandlerInterface
{
    /**
     * How long a route that answered without rate-limit headers is treated as
     * unmetered before a request probes it again.
     */
    private const UNMETERED_TTL = 60;

    private readonly BucketKeyResolver $bucketKeys;

    private readonly RetryStrategyInterface $retryStrategy;

    /**
     * Shown as the "of N" in retry warnings. Only known when the strategy is
     * the default one built from Options; a custom strategy keeps its own
     * limit.
     */
    private readonly ?int $retryLimit;

    /**
     * Probes in flight from this handler, by bucket key. Each promise settles
     * once its probe's response has been recorded and its lock released.
     *
     * Only a response can reveal an unknown allowance, so the probe goes
     * alone. Across processes the lock sees to that. Within this one, a
     * second request must not block on that lock: the probe holding it can
     * only land once the caller is free to wait on promises again. It queues
     * behind the probe's promise instead.
     *
     * @var array<string, PromiseInterface>
     */
    private array $probes = [];

    private readonly RateLimitParserInterface $parser;

    private readonly ClientLimiter $clientLimiter;

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
        ?RetryStrategyInterface $retryStrategy = null,
        ?RateLimitParserInterface $parser = null,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
        $this->bucketKeys = new BucketKeyResolver($this->store);
        $this->retryStrategy = $retryStrategy ?? StatusCodeRetryStrategy::fromOptions($this->options);
        $this->retryLimit = $retryStrategy === null ? $this->options->maxRetries : null;
        $this->parser = $parser ?? new HeaderRateLimitParser($this->headers);
        $this->clientLimiter = new ClientLimiter(
            $this->options->limits,
            $this->store,
            $this->lockFactory,
            $this->clock,
            $this->sleeper,
            $this->withMargin(...),
            $this->logger,
            $this->events,
        );
    }

    /**
     * Always returns a promise, never the response itself. Anything that stops
     * the request before it is sent (a spent invalid-request budget, a wait
     * longer than maxDelaySeconds) rejects it, the same way a failed transfer
     * would.
     *
     * @param array<string, mixed> $options
     */
    public function handle(callable $handler, RequestInterface $request, array $options): PromiseInterface
    {
        try {
            $overrides = RequestOptions::fromArray($options[RequestOptions::KEY] ?? null, $this->options);
            // Ours alone; nothing further down the stack has a use for it.
            unset($options[RequestOptions::KEY]);

            if (! $overrides->enabled) {
                return Create::promiseFor($handler($request, $options));
            }

            ['identity' => $identity, 'routeKey' => $routeKey, 'major' => $major] = $this->keysFor($request, $overrides);
            $this->enforceInvalidBudget($request, $identity);

            return $this->attempt($handler, $request, $options, $overrides, $identity, $routeKey, $major, 0);
        } catch (Throwable $e) {
            return Create::rejectionFor($e);
        }
    }

    /**
     * One pass through the bucket: claim capacity, hand the request on, and
     * chain the bookkeeping onto the transfer instead of waiting for it.
     *
     * A retry re-enters here from inside that chain, so the bucket key is
     * resolved afresh on every pass: the previous response may have revealed
     * a bucket hash that moves the request to a different bucket.
     *
     * @param array<string, mixed> $options
     */
    private function attempt(
        callable $handler,
        RequestInterface $request,
        array $options,
        RequestOptions $overrides,
        string $identity,
        string $routeKey,
        string $major,
        int $tries,
    ): PromiseInterface {
        $effectiveKey = $this->bucketKeys->effective($routeKey, $major, $identity);

        $probing = $this->probes[$effectiveKey] ?? null;
        if ($probing !== null) {
            $resume = fn (): PromiseInterface => $this->attempt($handler, $request, $options, $overrides, $identity, $routeKey, $major, $tries);

            return $probing->then($resume, $resume);
        }

        $probe = $this->reserveBucketCapacity($request, $effectiveKey, $overrides);

        try {
            $this->clientLimiter->reserve($request, $identity, $routeKey, fn (float $delay): float => $this->withMargin($delay, $overrides));
            $this->reserveGlobalCapacity($request, $identity, $overrides);

            $transfer = Create::promiseFor($handler($request, $options));
        } catch (Throwable $e) {
            $probe?->release();

            throw $e;
        }

        $recorded = $transfer->then(
            function (ResponseInterface $response) use ($routeKey, $major, $effectiveKey, $identity): array {
                $updated = $this->persist($response, $routeKey, $major, $identity, $effectiveKey);
                $this->recordGlobalLimit($response, $identity);
                $this->recordInvalidRequest($response, $identity);

                return [$response, $updated];
            },
            // Passed on explicitly: without a handler an already-rejected
            // transfer would come straight back to the caller, settled before
            // the probe below has been released.
            static fn (mixed $reason): PromiseInterface => Create::rejectionFor($reason),
        );

        if ($probe !== null) {
            // Chained after the bookkeeping above, so it runs once the response
            // has been recorded, whether the transfer fulfilled or failed. It
            // hangs off the transfer rather than the caller's chain so that a
            // cancelled request still gives the lock back.
            $release = function () use ($effectiveKey, $probe): void {
                unset($this->probes[$effectiveKey]);
                $probe->release();
            };

            $this->probes[$effectiveKey] = $transfer->then($release, $release);
        }

        return $recorded->then(
            /**
             * @param array{ResponseInterface, array{string, RateLimit}|null} $recorded
             */
            function (array $recorded) use ($handler, $request, $options, $overrides, $identity, $routeKey, $major, $tries): ResponseInterface|PromiseInterface {
                [$response, $updated] = $recorded;

                // Announced only once a probe's lock is gone, so a slow listener
                // cannot hold up callers queued behind an unknown bucket.
                if ($updated !== null) {
                    $this->dispatch(new RateLimitUpdated($request, $updated[1], $updated[0]));
                }

                $delay = $this->retryDelay($request, $response, $tries, $overrides);

                if ($delay !== null) {
                    $this->sleepForRetry($request, $response, ++$tries, $delay, $overrides);

                    return $this->attempt($handler, $request, $options, $overrides, $identity, $routeKey, $major, $tries);
                }

                $this->throwIfRateLimited($request, $response);

                return $response;
            },
        );
    }

    public function state(RequestInterface $request): ?RateLimit
    {
        return $this->liveState($this->store->get($this->effectiveKey($request)));
    }

    /**
     * Not under the bucket lock: this is a single delete, and a reservation
     * racing it merely leaves the old state standing.
     */
    public function forget(RequestInterface $request): void
    {
        $this->store->forget($this->effectiveKey($request));
    }

    public function globalBudget(RequestInterface $request): ?BudgetState
    {
        $config = $this->options->globalLimit;
        if ($config === null || $this->isExemptFromGlobalLimit($config, $request)) {
            return null;
        }

        return $this->budgetState($this->globalKey($this->identityResolver->resolve($request)), $config->maxRequests);
    }

    public function invalidRequestBudget(RequestInterface $request): ?BudgetState
    {
        $config = $this->options->invalidRequestLimit;
        if ($config === null) {
            return null;
        }

        return $this->budgetState($this->invalidKey($this->identityResolver->resolve($request)), $config->maxRequests);
    }

    /**
     * Where a request's state lives: the identity it is isolated by, and the
     * route key and major parameters that bucket-hash discovery re-keys from.
     *
     * Sending and inspecting both derive the keys here, so what an application
     * is shown is always the bucket the request would really wait on.
     *
     * An identity supplied per request is fingerprinted the way
     * {@see AuthorizationIdentityResolver} fingerprints credentials, so a tenant
     * id or token passed as the identity is never used in the clear.
     *
     * @return array{identity: string, routeKey: string, major: string}
     */
    private function keysFor(RequestInterface $request, ?RequestOptions $overrides = null): array
    {
        $identity = $overrides?->identity !== null
            ? hash('sha256', $overrides->identity)
            : $this->identityResolver->resolve($request);

        return [
            'identity' => $identity,
            'routeKey' => $identity . ':' . ($overrides->bucket ?? $this->resolver->resolve($request)),
            'major' => $this->resolver instanceof MajorParameterAwareInterface
                ? $this->resolver->majorParameters($request)
                : '',
        ];
    }

    private function effectiveKey(RequestInterface $request): string
    {
        ['identity' => $identity, 'routeKey' => $routeKey, 'major' => $major] = $this->keysFor($request);

        return $this->bucketKeys->effective($routeKey, $major, $identity);
    }

    /**
     * The stored state while its window is still open. Entries outlive their
     * reset (the TTL has a floor), but a lapsed window says nothing about the
     * next one, so it counts as unknown.
     *
     * @param array<string, mixed>|null $stored
     */
    private function liveState(?array $stored): ?RateLimit
    {
        $rateLimit = $stored === null ? null : RateLimit::fromArray($stored);

        return $rateLimit !== null && $rateLimit->secondsUntilReset($this->clock) > 0.0 ? $rateLimit : null;
    }

    /**
     * Read one of the self-imposed budget counters the way enforcing it does:
     * a lapsed window counts as empty, and during a global 429 block the
     * stored sentinel count is reported as a spent budget.
     */
    private function budgetState(string $key, int $max): BudgetState
    {
        $now = $this->clock->now();
        $state = $this->store->get($key) ?? [];
        $reset = (float) ($state['reset'] ?? 0.0);
        $blockedUntil = (float) ($state['blocked_until'] ?? 0.0);

        if ($blockedUntil > $now) {
            return new BudgetState($max, $max, max($reset, $blockedUntil), $blockedUntil);
        }

        return $reset > $now
            ? new BudgetState((int) ($state['count'] ?? 0), $max, $reset)
            : new BudgetState(0, $max);
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
    private function reserveBucketCapacity(RequestInterface $request, string $effectiveKey, RequestOptions $overrides): ?LockInterface
    {
        if ($this->store instanceof AtomicReservationStoreInterface) {
            return $this->reserveAtomically($request, $this->store, $effectiveKey, $overrides);
        }

        $waitedOut = null;

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

                $rateLimit = $this->liveState($stored);

                // Unknown allowance, or the very window already waited out: only
                // a response can reveal the next one, so go alone. Any other
                // window means another caller's response landed during the
                // sleep, and it counts like any other: it may be spent too.
                if ($rateLimit === null || $rateLimit->reset === $waitedOut) {
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

                $delay = $this->withMargin($rateLimit->secondsUntilReset($this->clock), $overrides);
                $waitedOut = $rateLimit->reset;
            } finally {
                if (! $keepLock) {
                    $lock->release();
                }
            }

            $this->waitForBucket($request, $effectiveKey, $delay);
        }
    }

    private function waitForBucket(RequestInterface $request, string $effectiveKey, float $delay): void
    {
        $this->logger->debug(sprintf(
            'Rate limit bucket "%s" exhausted; sleeping %.2fs until reset.',
            $effectiveKey,
            $delay,
        ), [
            'key' => $effectiveKey,
            'delay' => $delay,
            'reason' => DelayReason::Bucket->value,
            'method' => $request->getMethod(),
        ]);

        $this->dispatch(new RequestDelayed($request, $delay, DelayReason::Bucket, $effectiveKey));

        $this->sleeper->sleep($delay);
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
    private function reserveAtomically(
        RequestInterface $request,
        AtomicReservationStoreInterface $store,
        string $effectiveKey,
        RequestOptions $overrides,
    ): ?LockInterface {
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

            $this->waitForBucket($request, $effectiveKey, $this->withMargin($reservation->delaySeconds, $overrides));
        }
    }

    /**
     * Update the stored state from the response, re-keying via bucket-hash
     * discovery when the API reveals a bucket. The next attempt picks the new
     * key up from the store.
     *
     * Both writes below read the stored window first, so they run under the
     * bucket's lock: a reservation landing between the read and the write
     * would otherwise be overwritten, handing a spent slot back.
     *
     * A response the parser finds nothing in leaves a known window alone.
     *
     * Returns the key the state ended up under with that state, or null when
     * the response held none.
     *
     * @return array{string, RateLimit}|null
     */
    private function persist(
        ResponseInterface $response,
        string $routeKey,
        string $major,
        string $identity,
        string $effectiveKey,
    ): ?array {
        $rateLimit = $this->parser->parse($response, $this->clock);

        if ($rateLimit === null) {
            $this->underBucketLock($effectiveKey, fn () => $this->recordUnmetered($response, $effectiveKey));

            return null;
        }

        // Discovery is switched on by Headers, not by the parser, so a parser
        // that only knows the allowance still gets re-keyed by the bucket header.
        $bucketHash = $rateLimit->bucketHash ?? HeaderParser::value($response, $this->headers->bucket);

        if ($this->headers->needsToCheckBucketHeader() && $bucketHash !== null) {
            $effectiveKey = $this->bucketKeys->observe($routeKey, (string) $bucketHash, $major, $identity);
        }

        // Reservations on an atomic store never take this lock, so only the store
        // itself can merge without overwriting one.
        $merged = $this->store instanceof AtomicReservationStoreInterface
            ? RateLimit::fromArray($this->store->merge($effectiveKey, $rateLimit->toArray(), $this->ttlFor($rateLimit), $this->clock->now()))
            : $this->underBucketLock($effectiveKey, function () use ($effectiveKey, $rateLimit): RateLimit {
                $merged = $this->lowestSeen($effectiveKey, $rateLimit);

                $this->store->put($effectiveKey, $merged->toArray(), $this->ttlFor($merged));

                return $merged;
            });

        return [$effectiveKey, $merged];
    }

    /**
     * A probe in flight from this handler already holds the key's lock, whether
     * it is the request being recorded or another one on the same bucket.
     * Taking it again would block forever on a per-handle lock such as flock,
     * since that probe can only land once this process is free again.
     *
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    private function underBucketLock(string $key, callable $write): mixed
    {
        $lock = isset($this->probes[$key]) ? null : $this->lockFactory->make($key);
        $lock?->acquire();

        try {
            return $write();
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
     * within {@see RateLimit::SAME_WINDOW_SECONDS} is treated as the same window.
     */
    private function lowestSeen(string $effectiveKey, RateLimit $fresh): RateLimit
    {
        $stored = $this->store->get($effectiveKey);

        if ($stored === null) {
            return $fresh;
        }

        $previous = RateLimit::fromArray($stored);

        if ($fresh->reset - $previous->reset >= RateLimit::SAME_WINDOW_SECONDS || $previous->secondsUntilReset($this->clock) <= 0.0) {
            return $fresh;
        }

        return $fresh->remaining <= $previous->remaining
            ? $fresh
            : $fresh->withRemaining($previous->remaining);
    }

    /**
     * A request's own max_retries replaces the default strategy's count. A
     * strategy passed in keeps its own rules, so the override can only stop it
     * sooner.
     */
    private function retryDelay(RequestInterface $request, ResponseInterface $response, int $tries, RequestOptions $overrides): ?float
    {
        if ($overrides->retriesOverridden && $tries >= $overrides->maxRetries) {
            return null;
        }

        $strategy = $overrides->retriesOverridden && $this->retryLimit !== null
            ? new StatusCodeRetryStrategy($overrides->maxRetries, $this->options->retryStatusCodes)
            : $this->retryStrategy;

        return $strategy->retryDelay($request, $response, $tries + 1, $this->hintedDelay($response));
    }

    /**
     * The resolver's reading of how long the response asks us to wait, for
     * the retry strategy to go on. A success is never a rate limit, and some
     * resolvers parse the body to find out, so only errors are asked.
     */
    private function hintedDelay(ResponseInterface $response): float
    {
        return $response->getStatusCode() >= 400 ? $this->retryAfter($response) : 0.0;
    }

    private function sleepForRetry(
        RequestInterface $request,
        ResponseInterface $response,
        int $tries,
        float $delay,
        RequestOptions $overrides,
    ): void {
        $delay = $this->withMargin(max(0.0, $delay), $overrides);
        $limit = $this->retryLimit === null ? null : $overrides->maxRetries;
        $global = $this->isGlobal($response);

        $this->logger->warning(sprintf(
            'Received %d for %s%s. Retry %s in %.2fs.',
            $response->getStatusCode(),
            $this->resolver->resolve($request),
            $global ? ' (global rate limit)' : '',
            $limit === null ? (string) $tries : $tries . '/' . $limit,
            $delay,
        ), [
            // Never the URI: webhook and interaction URLs carry tokens, and any
            // API can take a key in the query string.
            'method' => $request->getMethod(),
            'status' => $response->getStatusCode(),
            'attempt' => $tries,
            'max_retries' => $limit,
            'delay' => $delay,
            'global' => $global,
        ]);

        $this->dispatch(new RetryScheduled(
            $request,
            $response,
            $tries,
            $limit,
            $delay,
            $global,
        ));

        $this->sleeper->sleep($delay);
    }

    private function throwIfRateLimited(RequestInterface $request, ResponseInterface $response): void
    {
        if (! $this->options->throwOnRateLimit || ! $this->retryStrategy->isRateLimited($response)) {
            return;
        }

        $retryAfter = $this->retryAfter($response);
        $global = $this->isGlobal($response);

        $this->dispatch(new RateLimitExceeded($request, $response, $retryAfter, $global));

        throw new RateLimitExceededException($request, $response, $retryAfter, $global, $this->resolver->resolve($request));
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

    private function withMargin(float $delay, ?RequestOptions $overrides = null): float
    {
        $delay += $this->options->safetyBufferSeconds;

        if ($this->options->jitterPercent > 0.0) {
            $delay += $delay * ($this->options->jitterPercent / 100.0) * (mt_rand() / mt_getrandmax());
        }

        $maxDelay = $overrides !== null ? $overrides->maxDelaySeconds : $this->options->maxDelaySeconds;

        if ($maxDelay !== null && $delay > $maxDelay) {
            throw new RateLimitDelayExceededException($delay);
        }

        return $delay;
    }

    private function reserveGlobalCapacity(RequestInterface $request, string $identity, RequestOptions $overrides): void
    {
        $config = $this->options->globalLimit;
        if ($config === null || $this->isExemptFromGlobalLimit($config, $request)) {
            return;
        }

        $key = $this->globalKey($identity);
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

            $delay = $this->withMargin($delay, $overrides);

            $this->logger->debug(sprintf(
                'Global rate limit "%s" exhausted; sleeping %.2fs until reset.',
                $key,
                $delay,
            ), [
                'key' => $key,
                'delay' => $delay,
                'reason' => DelayReason::GlobalLimit->value,
                'method' => $request->getMethod(),
            ]);

            $this->dispatch(new RequestDelayed($request, $delay, DelayReason::GlobalLimit, $key));

            $this->sleeper->sleep($delay);
        }
    }

    private function recordGlobalLimit(ResponseInterface $response, string $identity): void
    {
        if ($response->getStatusCode() !== 429 || ! $this->isGlobal($response)) {
            return;
        }
        $delay = $this->retryAfter($response);
        $key = $this->globalKey($identity);
        $this->store->put($key, ['count' => PHP_INT_MAX, 'reset' => $this->clock->now() + $delay, 'blocked_until' => $this->clock->now() + $delay], (int) ceil($delay) + 1);
    }

    private function enforceInvalidBudget(RequestInterface $request, string $identity): void
    {
        $config = $this->options->invalidRequestLimit;
        if ($config === null) {
            return;
        }
        $state = $this->store->get($this->invalidKey($identity));
        if ($state !== null && (int) ($state['count'] ?? 0) >= $config->maxRequests && (float) ($state['reset'] ?? 0.0) > $this->clock->now()) {
            $retryAfter = (float) $state['reset'] - $this->clock->now();

            $this->dispatch(new InvalidRequestLimitReached($request, $retryAfter));

            throw new InvalidRequestLimitExceededException($retryAfter);
        }
    }

    private function recordInvalidRequest(ResponseInterface $response, string $identity): void
    {
        $config = $this->options->invalidRequestLimit;
        $status = $response->getStatusCode();
        if ($config === null || ! in_array($status, $config->statusCodes, true) || ($status === 429 && $this->hasIgnoredScope($response, $config))) {
            return;
        }
        $key = $this->invalidKey($identity);
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

    private function globalKey(string $identity): string
    {
        return 'global:' . hash('sha256', $identity);
    }

    private function invalidKey(string $identity): string
    {
        return 'invalid:' . hash('sha256', $identity);
    }

    /**
     * A 429 in some scopes says nothing about this client misbehaving (Discord's
     * "shared": another caller spent a per-resource allowance). Which scopes is
     * the API's call, so they come from the configuration; a response without a
     * scope header has no scope and always counts.
     */
    private function hasIgnoredScope(ResponseInterface $response, InvalidRequestLimit $config): bool
    {
        $scope = HeaderParser::value($response, $this->headers->scope);

        return $scope !== null
            && in_array(strtolower((string) $scope), array_map(strtolower(...), $config->ignoredScopes), true);
    }

    /**
     * Routes an API keeps outside its global budget (Discord's interaction
     * callbacks, for one) are listed on the configuration rather than known
     * here, so one API's exemptions never leak into another's budget.
     */
    private function isExemptFromGlobalLimit(GlobalLimit $config, RequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();

        foreach ($config->exemptPaths as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function ttlFor(RateLimit $rateLimit): int
    {
        $seconds = (int) ceil($rateLimit->secondsUntilReset($this->clock))
            + (int) ceil($this->options->safetyBufferSeconds);

        return min(max($seconds, 60), $this->options->maxStoreTtl);
    }

    private function dispatch(object $event): void
    {
        $this->events?->dispatch($event);
    }
}
