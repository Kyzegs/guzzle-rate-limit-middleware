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
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockFactoryInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\MajorParameterAwareInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\SleeperInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\StoreInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\RateLimitExceededException;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimit;
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
    ) {
        $this->bucketKeys = new BucketKeyResolver($this->store);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function handle(callable $handler, RequestInterface $request, array $options): mixed
    {
        $routeKey = $this->resolver->resolve($request);
        $major = $this->resolver instanceof MajorParameterAwareInterface
            ? $this->resolver->majorParameters($request)
            : '';
        $effectiveKey = $this->bucketKeys->effective($routeKey, $major);

        $lock = $this->lockFactory->make($effectiveKey);
        $lock->acquire();

        try {
            $tries = 0;

            while (true) {
                $this->delayIfNeeded($effectiveKey);

                $promise = $handler($request, $options);
                $isPromise = $promise instanceof PromiseInterface;
                $response = $isPromise ? $promise->wait() : $promise;

                $effectiveKey = $this->persist($response, $routeKey, $major, $effectiveKey);

                if ($this->shouldRetry($response, $tries)) {
                    $this->sleepForRetry($request, $response, ++$tries);

                    continue;
                }

                $this->throwIfRateLimited($request, $response);

                return $isPromise ? Create::promiseFor($response) : $response;
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Sleep if the stored bucket state says the next request must wait.
     */
    private function delayIfNeeded(string $effectiveKey): void
    {
        $stored = $this->store->get($effectiveKey);
        if ($stored === null) {
            return;
        }

        $rateLimit = RateLimit::fromArray($stored);
        if (! $rateLimit->shouldDelay($this->clock)) {
            return;
        }

        $delay = $this->withMargin($rateLimit->secondsUntilReset($this->clock));

        $this->logger->debug(sprintf(
            'Rate limit bucket "%s" exhausted; sleeping %.2fs until reset.',
            $effectiveKey,
            $delay,
        ));

        $this->sleeper->sleep($delay);
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

        $this->store->put($effectiveKey, $rateLimit->toArray(), $this->ttlFor($rateLimit));

        return $effectiveKey;
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
        $retryAfter = HeaderParser::value($response, $this->headers->retryAfter);
        if ($retryAfter !== null) {
            return (float) $retryAfter;
        }

        return RateLimit::fromResponse($response, $this->headers, $this->clock)
            ->secondsUntilReset($this->clock);
    }

    private function isGlobal(ResponseInterface $response): bool
    {
        $global = HeaderParser::value($response, $this->headers->global);
        if ($global !== null && strtolower((string) $global) === 'true') {
            return true;
        }

        $scope = HeaderParser::value($response, $this->headers->scope);

        return $scope !== null && strtolower((string) $scope) === 'global';
    }

    private function withMargin(float $delay): float
    {
        $delay += $this->options->safetyBufferSeconds;

        if ($this->options->jitterPercent > 0.0) {
            $delay += $delay * ($this->options->jitterPercent / 100.0) * (mt_rand() / mt_getrandmax());
        }

        return $delay;
    }

    private function ttlFor(RateLimit $rateLimit): int
    {
        $seconds = (int) ceil($rateLimit->secondsUntilReset($this->clock))
            + (int) ceil($this->options->safetyBufferSeconds);

        return min(max($seconds, 60), $this->options->maxStoreTtl);
    }
}
