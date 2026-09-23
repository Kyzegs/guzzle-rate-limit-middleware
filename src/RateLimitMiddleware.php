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
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Support\NullLockFactory;
use Kyzegs\GuzzleRateLimitMiddleware\Support\SystemClock;
use Kyzegs\GuzzleRateLimitMiddleware\Support\UsleepSleeper;
use Kyzegs\GuzzleRateLimitMiddleware\Support\DefaultRetryAfterResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Support\DiscordRetryAfterResolver;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Guzzle middleware that prevents 429s by reading rate-limit response headers
 * and delaying requests when a bucket is exhausted. Push it onto a HandlerStack.
 *
 * Use the named-argument {@see create()} factory for full control, or the
 * per-API presets ({@see discord()}, {@see github()}, {@see twitter()},
 * {@see ietf()}, {@see shopify()}).
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
        ?ClockInterface $clock = null,
        ?SleeperInterface $sleeper = null,
        ?HandlerInterface $handler = null,
        ?IdentityResolverInterface $identityResolver = null,
        ?RetryAfterResolverInterface $retryAfterResolver = null,
        ?RateLimitParserInterface $parser = null,
    ) {
        $clock ??= new SystemClock();

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
            $parser,
        );
    }

    /**
     * Build a middleware with explicit, named dependencies.
     */
    public static function create(
        Options $options = new Options(),
        Headers $headers = new Headers(),
        ?StoreInterface $store = null,
        ?LoggerInterface $logger = null,
        ?BucketResolverInterface $resolver = null,
        ?LockFactoryInterface $lockFactory = null,
        ?ClockInterface $clock = null,
        ?SleeperInterface $sleeper = null,
        ?IdentityResolverInterface $identityResolver = null,
        ?RetryAfterResolverInterface $retryAfterResolver = null,
        ?RateLimitParserInterface $parser = null,
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
            parser: $parser,
        );
    }

    public static function discord(
        ?StoreInterface $store = null,
        ?LoggerInterface $logger = null,
        ?LockFactoryInterface $lockFactory = null,
        Options $options = new Options(globalLimit: new GlobalLimit(), invalidRequestLimit: new InvalidRequestLimit()),
    ): self {
        return self::create(
            options: $options,
            headers: Headers::discord(),
            store: $store,
            logger: $logger,
            resolver: new DiscordBucketResolver(),
            lockFactory: $lockFactory,
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

    /**
     * The IETF draft's structured `RateLimit`/`RateLimit-Policy` fields, falling
     * back to the older separate `RateLimit-*` headers.
     */
    public static function ietf(?StoreInterface $store = null, ?LoggerInterface $logger = null): self
    {
        return self::create(
            headers: Headers::ietf(),
            store: $store,
            logger: $logger,
            parser: new IetfRateLimitParser(),
        );
    }

    /**
     * Shopify's REST Admin API: a leaky bucket per app and store, reported as
     * "used/limit" in `X-Shopify-Shop-Api-Call-Limit`. Every endpoint drains
     * the same bucket, so state is kept per shop host rather than per route.
     * A 429 is retried after its `Retry-After`.
     */
    public static function shopify(?StoreInterface $store = null, ?LoggerInterface $logger = null): self
    {
        return self::create(
            store: $store,
            logger: $logger,
            resolver: new HostBucketResolver(),
            parser: new FractionHeaderParser('X-Shopify-Shop-Api-Call-Limit'),
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
