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
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RetryAfterResolverInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockFactoryInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\SleeperInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\StoreInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Handler\RateLimitHandler;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\DefaultBucketResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\DiscordBucketResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\AuthorizationIdentityResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Support\NullLockFactory;
use Kyzegs\GuzzleRateLimitMiddleware\Support\Psr20Clock;
use Kyzegs\GuzzleRateLimitMiddleware\Support\SystemClock;
use Kyzegs\GuzzleRateLimitMiddleware\Support\UsleepSleeper;
use Kyzegs\GuzzleRateLimitMiddleware\Support\DefaultRetryAfterResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Support\DiscordRetryAfterResolver;
use Psr\Clock\ClockInterface as PsrClockInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Guzzle middleware that prevents 429s by reading rate-limit response headers
 * and delaying requests when a bucket is exhausted. Push it onto a HandlerStack.
 *
 * Use the named-argument {@see create()} factory for full control, or the
 * per-API presets ({@see discord()}, {@see github()}, {@see twitter()},
 * {@see ietf()}, {@see gitlab()}, {@see twitch()}, {@see reddit()}).
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
        );
    }

    public static function github(?StoreInterface $store = null, ?LoggerInterface $logger = null): self
    {
        return self::create(headers: Headers::github(), store: $store, logger: $logger);
    }

    public static function twitter(?StoreInterface $store = null, ?LoggerInterface $logger = null): self
    {
        return self::create(headers: Headers::twitter(), store: $store, logger: $logger);
    }

    public static function ietf(?StoreInterface $store = null, ?LoggerInterface $logger = null): self
    {
        return self::create(headers: Headers::ietf(), store: $store, logger: $logger);
    }

    public static function gitlab(?StoreInterface $store = null, ?LoggerInterface $logger = null): self
    {
        return self::create(headers: Headers::gitlab(), store: $store, logger: $logger);
    }

    public static function twitch(?StoreInterface $store = null, ?LoggerInterface $logger = null): self
    {
        return self::create(headers: Headers::twitch(), store: $store, logger: $logger);
    }

    public static function reddit(?StoreInterface $store = null, ?LoggerInterface $logger = null): self
    {
        return self::create(headers: Headers::reddit(), store: $store, logger: $logger);
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
}
