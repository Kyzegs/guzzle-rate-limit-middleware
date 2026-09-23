<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware;

use Closure;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\Config\GlobalLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\InvalidRequestLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\BucketResolverInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\ClockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\HandlerInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\IdentityResolverInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\InspectableHandlerInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RetryAfterResolverInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RetryStrategyInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockFactoryInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RateLimitParserInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\SleeperInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\StoreInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Handler\RateLimitHandler;
use Kyzegs\GuzzleRateLimitMiddleware\Parser\FractionHeaderParser;
use Kyzegs\GuzzleRateLimitMiddleware\Parser\IetfRateLimitParser;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\DefaultBucketResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\DiscordBucketResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\HostBucketResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\AuthorizationIdentityResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Retry\GitHubRetryStrategy;
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Support\NullLockFactory;
use Kyzegs\GuzzleRateLimitMiddleware\Support\Psr20Clock;
use Kyzegs\GuzzleRateLimitMiddleware\Support\SystemClock;
use Kyzegs\GuzzleRateLimitMiddleware\Support\UsleepSleeper;
use Kyzegs\GuzzleRateLimitMiddleware\Support\DefaultRetryAfterResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Support\DiscordRetryAfterResolver;
use LogicException;
use Psr\Clock\ClockInterface as PsrClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Guzzle middleware that prevents 429s by reading rate-limit response headers
 * and delaying requests when a bucket is exhausted. Push it onto a HandlerStack.
 *
 * Use the named-argument {@see create()} factory for full control, or the
 * per-API presets ({@see discord()}, {@see github()}, {@see twitter()},
 * {@see ietf()}, {@see gitlab()}, {@see twitch()}, {@see reddit()}, {@see shopify()}).
 * Every one of them takes an optional PSR-14 `events` dispatcher for observing
 * delays, retries and limits.
 */
final class RateLimitMiddleware
{
    private readonly HandlerInterface $handler;

    public function __construct(
        Options $options = new Options(),
        Headers $headers = new Headers(),
        ?StoreInterface $store = null,
        ?LoggerInterface $logger = null,
        ?BucketResolverInterface $resolver = null,
        ?LockFactoryInterface $lockFactory = null,
        ClockInterface|PsrClockInterface|null $clock = null,
        ?SleeperInterface $sleeper = null,
        ?HandlerInterface $handler = null,
        ?IdentityResolverInterface $identityResolver = null,
        ?RetryAfterResolverInterface $retryAfterResolver = null,
        ?RetryStrategyInterface $retryStrategy = null,
        ?RateLimitParserInterface $parser = null,
        ?EventDispatcherInterface $events = null,
    ) {
        $clock = match (true) {
            $clock instanceof ClockInterface => $clock,
            $clock instanceof PsrClockInterface => new Psr20Clock($clock),
            default => new SystemClock(),
        };

        $this->handler = $handler ?? new RateLimitHandler(
            $options,
            $headers,
            $store ?? new InMemoryStore($clock),
            $logger ?? new NullLogger(),
            $resolver ?? new DefaultBucketResolver(),
            $lockFactory ?? new NullLockFactory(),
            $clock,
            $sleeper ?? new UsleepSleeper(),
            $identityResolver ?? new AuthorizationIdentityResolver(),
            $retryAfterResolver ?? new DefaultRetryAfterResolver(),
            $retryStrategy,
            $parser,
            $events,
        );
    }

    /**
     * Build a middleware with explicit, named dependencies.
     *
     * The clock may be this package's ClockInterface or any PSR-20 clock.
     */
    public static function create(
        Options $options = new Options(),
        Headers $headers = new Headers(),
        ?StoreInterface $store = null,
        ?LoggerInterface $logger = null,
        ?BucketResolverInterface $resolver = null,
        ?LockFactoryInterface $lockFactory = null,
        ClockInterface|PsrClockInterface|null $clock = null,
        ?SleeperInterface $sleeper = null,
        ?IdentityResolverInterface $identityResolver = null,
        ?RetryAfterResolverInterface $retryAfterResolver = null,
        ?RetryStrategyInterface $retryStrategy = null,
        ?RateLimitParserInterface $parser = null,
        ?EventDispatcherInterface $events = null,
    ): self {
        return new self(
            options: $options,
            headers: $headers,
            store: $store,
            logger: $logger,
            resolver: $resolver,
            lockFactory: $lockFactory,
            clock: $clock,
            sleeper: $sleeper,
            identityResolver: $identityResolver,
            retryAfterResolver: $retryAfterResolver,
            retryStrategy: $retryStrategy,
            parser: $parser,
            events: $events,
        );
    }

    /**
     * Discord headers, bucket-hash discovery and major parameters, a global
     * budget and an invalid-request budget.
     *
     * Interaction callbacks stay exempt from the global budget even when
     * $options carries a GlobalLimit of your own: Discord never counts them, so
     * the preset adds {@see GlobalLimit::discord()}'s exemptions to whatever
     * limit it is given rather than trusting every caller to repeat them.
     *
     * $clock is shared with the bucket resolver, which dates messages to pick
     * the old-message deletion bucket, so both run on the same time.
     */
    public static function discord(
        ?StoreInterface $store = null,
        ?LoggerInterface $logger = null,
        ?LockFactoryInterface $lockFactory = null,
        Options $options = new Options(globalLimit: new GlobalLimit(), invalidRequestLimit: new InvalidRequestLimit()),
        ?ClockInterface $clock = null,
        ?EventDispatcherInterface $events = null,
    ): self {
        $clock ??= new SystemClock();

        return self::create(
            options: self::withDiscordExemptions($options),
            headers: Headers::discord(),
            store: $store,
            logger: $logger,
            resolver: new DiscordBucketResolver($clock),
            lockFactory: $lockFactory,
            clock: $clock,
            identityResolver: new AuthorizationIdentityResolver(),
            retryAfterResolver: new DiscordRetryAfterResolver(),
            events: $events,
        );
    }

    /**
     * GitHub answers a rate limit with a 403 as well as a 429, so this retries
     * by {@see GitHubRetryStrategy} rather than by status code alone.
     */
    public static function github(
        ?StoreInterface $store = null,
        ?LoggerInterface $logger = null,
        ?EventDispatcherInterface $events = null,
    ): self {
        return self::create(
            headers: Headers::github(),
            store: $store,
            logger: $logger,
            retryStrategy: new GitHubRetryStrategy(),
            events: $events,
        );
    }

    public static function twitter(
        ?StoreInterface $store = null,
        ?LoggerInterface $logger = null,
        ?EventDispatcherInterface $events = null,
    ): self {
        return self::create(headers: Headers::twitter(), store: $store, logger: $logger, events: $events);
    }

    /**
     * The IETF draft's structured `RateLimit`/`RateLimit-Policy` fields, falling
     * back to the older separate `RateLimit-*` headers.
     */
    public static function ietf(
        ?StoreInterface $store = null,
        ?LoggerInterface $logger = null,
        ?EventDispatcherInterface $events = null,
    ): self {
        return self::create(
            headers: Headers::ietf(),
            store: $store,
            logger: $logger,
            parser: new IetfRateLimitParser(),
            events: $events,
        );
    }

    /**
     * Shopify's REST Admin API: a leaky bucket per app and store, reported as
     * "used/limit" in `X-Shopify-Shop-Api-Call-Limit`. Every endpoint drains
     * the same bucket, so state is kept per shop host rather than per route.
     * A 429 is retried after its `Retry-After`.
     */
    public static function shopify(
        ?StoreInterface $store = null,
        ?LoggerInterface $logger = null,
        ?EventDispatcherInterface $events = null,
    ): self {
        return self::create(
            store: $store,
            logger: $logger,
            resolver: new HostBucketResolver(),
            parser: new FractionHeaderParser('X-Shopify-Shop-Api-Call-Limit'),
            events: $events,
        );
    }

    public static function gitlab(
        ?StoreInterface $store = null,
        ?LoggerInterface $logger = null,
        ?EventDispatcherInterface $events = null,
    ): self {
        return self::create(headers: Headers::gitlab(), store: $store, logger: $logger, events: $events);
    }

    public static function twitch(
        ?StoreInterface $store = null,
        ?LoggerInterface $logger = null,
        ?EventDispatcherInterface $events = null,
    ): self {
        return self::create(headers: Headers::twitch(), store: $store, logger: $logger, events: $events);
    }

    public static function reddit(
        ?StoreInterface $store = null,
        ?LoggerInterface $logger = null,
        ?EventDispatcherInterface $events = null,
    ): self {
        return self::create(headers: Headers::reddit(), store: $store, logger: $logger, events: $events);
    }

    /**
     * Add Discord's global-budget exemptions that $options' GlobalLimit is
     * missing, keeping its own budget and exemptions.
     */
    private static function withDiscordExemptions(Options $options): Options
    {
        $globalLimit = $options->globalLimit;
        if ($globalLimit === null) {
            return $options;
        }

        $missing = array_diff(GlobalLimit::discord()->exemptPaths, $globalLimit->exemptPaths);
        if ($missing === []) {
            return $options;
        }

        return $options->withGlobalLimit(
            $globalLimit->withExemptPaths([...$globalLimit->exemptPaths, ...array_values($missing)]),
        );
    }

    /**
     * Guzzle middleware entry point.
     */
    public function __invoke(callable $handler): Closure
    {
        return fn (RequestInterface $request, array $options) => $this->handler->handle($handler, $request, $options);
    }

    /**
     * The stored state of the bucket $request would use right now, or null
     * when nothing is known yet or its window has already reset.
     *
     * Pass the request as the client sends it (absolute URI, Authorization
     * header): both decide which bucket it lands in.
     *
     * @throws LogicException when a custom handler does not support inspection
     *
     * @phpstan-impure
     */
    public function state(RequestInterface $request): ?RateLimit
    {
        return $this->inspectable(__FUNCTION__)->state($request);
    }

    /**
     * Drop the stored state of the bucket $request would use, so the next
     * request goes out without waiting.
     *
     * @throws LogicException when a custom handler does not support inspection
     */
    public function forget(RequestInterface $request): void
    {
        $this->inspectable(__FUNCTION__)->forget($request);
    }

    /**
     * The global request budget of $request's identity, or null when no
     * {@see GlobalLimit} is configured or $request is exempt from it.
     *
     * @throws LogicException when a custom handler does not support inspection
     *
     * @phpstan-impure
     */
    public function globalBudget(RequestInterface $request): ?BudgetState
    {
        return $this->inspectable(__FUNCTION__)->globalBudget($request);
    }

    /**
     * The invalid-request budget of $request's identity, or null when no
     * {@see InvalidRequestLimit} is configured.
     *
     * @throws LogicException when a custom handler does not support inspection
     *
     * @phpstan-impure
     */
    public function invalidRequestBudget(RequestInterface $request): ?BudgetState
    {
        return $this->inspectable(__FUNCTION__)->invalidRequestBudget($request);
    }

    private function inspectable(string $method): InspectableHandlerInterface
    {
        if (! $this->handler instanceof InspectableHandlerInterface) {
            throw new LogicException(sprintf(
                'Cannot call %s(): the handler %s does not implement %s.',
                $method,
                $this->handler::class,
                InspectableHandlerInterface::class,
            ));
        }

        return $this->handler;
    }
}
