<?php

namespace Kyzegs\GuzzleRateLimitMiddleware;

use Closure;
use Kyzegs\GuzzleRateLimitMiddleware\BucketHashHandlers\BucketHashDiscovery;
use Kyzegs\GuzzleRateLimitMiddleware\BucketHashHandlers\NullBucketHashDiscovery;
use Kyzegs\GuzzleRateLimitMiddleware\CacheHandlers\ArrayCacheHandler;
use Kyzegs\GuzzleRateLimitMiddleware\Configuration\RateLimitConfig;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\CacheHandlerInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\HandlerInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockHandlerInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LoggerInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RouteResolverInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Handlers\RateLimitHandler;
use Kyzegs\GuzzleRateLimitMiddleware\LockHandlers\NullLockHandler;
use Kyzegs\GuzzleRateLimitMiddleware\Loggers\NullLogger;
use Kyzegs\GuzzleRateLimitMiddleware\RetryHandlers\NullRetryHandler;
use Kyzegs\GuzzleRateLimitMiddleware\RetryHandlers\StandardRetryHandler;
use Kyzegs\GuzzleRateLimitMiddleware\RouteResolvers\DefaultRouteResolver;
use Psr\Http\Message\RequestInterface;

/**
 * Guzzle middleware for intelligent rate limiting based on response headers.
 * 
 * This class acts as a factory and orchestrator for the actual handling logic,
 * which can be customized by providing different HandlerInterface implementations.
 */
class RateLimitMiddleware
{
    private readonly HandlerInterface $handler;

    public function __construct(
        ?HandlerInterface $handler = null,
        ?CacheHandlerInterface $cacheHandler = null,
        ?RouteResolverInterface $routeResolver = null,
        ?RateLimitConfig $config = null,
        ?LockHandlerInterface $lockHandler = null,
        ?LoggerInterface $logger = null,
        int $maxRetries = 0,
        bool $enableBucketHashDiscovery = false
    ) {
        if ($handler !== null) {
            $this->handler = $handler;
            return;
        }

        // Create default handler if none provided
        $resolvedConfig = $config ?? new RateLimitConfig();
        
        $bucketManager = new BucketManager(
            $cacheHandler ?? new ArrayCacheHandler(),
            $routeResolver ?? new DefaultRouteResolver(),
            $resolvedConfig
        );

        $this->handler = new RateLimitHandler(
            bucketManager: $bucketManager,
            lockHandler: $lockHandler ?? new NullLockHandler(),
            logger: $logger ?? new NullLogger(),
            retryHandler: $maxRetries > 0 ? new StandardRetryHandler($maxRetries, $resolvedConfig) : new NullRetryHandler(),
            bucketHashDiscovery: $enableBucketHashDiscovery 
                ? new BucketHashDiscovery($bucketManager, true)
                : new NullBucketHashDiscovery()
        );
    }

    /**
     * Create a new middleware instance with custom handler.
     */
    public static function create(HandlerInterface $handler): static
    {
        return new static($handler);
    }

    /**
     * Create a new middleware instance with custom configuration using default handler.
     */
    public static function createWithDefaults(
        CacheHandlerInterface $cacheHandler,
        RouteResolverInterface $routeResolver,
        RateLimitConfig $config,
        ?LockHandlerInterface $lockHandler = null,
        ?LoggerInterface $logger = null,
        int $maxRetries = 0,
        bool $enableBucketHashDiscovery = false
    ): static {
        return new static(
            handler: null,
            cacheHandler: $cacheHandler,
            routeResolver: $routeResolver,
            config: $config,
            lockHandler: $lockHandler,
            logger: $logger,
            maxRetries: $maxRetries,
            enableBucketHashDiscovery: $enableBucketHashDiscovery
        );
    }

    /**
     * Main middleware handler - delegates to the handler.
     */
    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            return $this->handler->handle($handler, $request, $options);
        };
    }
}