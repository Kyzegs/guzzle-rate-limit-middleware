<?php

namespace Kyzegs\GuzzleRateLimitMiddleware;

use Closure;
use Kyzegs\GuzzleRateLimitMiddleware\BucketHashHandlers\BucketHashDiscovery;
use Kyzegs\GuzzleRateLimitMiddleware\BucketHashHandlers\NullBucketHashDiscovery;
use Kyzegs\GuzzleRateLimitMiddleware\CacheHandlers\ArrayCacheHandler;
use Kyzegs\GuzzleRateLimitMiddleware\Configuration\RateLimitConfig;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\CacheHandlerInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockHandlerInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LoggerInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RouteResolverInterface;
use Kyzegs\GuzzleRateLimitMiddleware\LockHandlers\NullLockHandler;
use Kyzegs\GuzzleRateLimitMiddleware\Loggers\NullLogger;
use Kyzegs\GuzzleRateLimitMiddleware\RetryHandlers\NullRetryHandler;
use Kyzegs\GuzzleRateLimitMiddleware\RetryHandlers\StandardRetryHandler;
use Kyzegs\GuzzleRateLimitMiddleware\RouteResolvers\DefaultRouteResolver;
use Kyzegs\GuzzleRateLimitMiddleware\RouteResolvers\DiscordRouteResolver;
use Psr\Http\Message\RequestInterface;

/**
 * Guzzle middleware for intelligent rate limiting based on response headers.
 * 
 * This class follows SOLID principles by acting as a factory and orchestrator
 * for the actual processing logic, which is handled by dedicated classes.
 */
class RateLimitMiddleware
{
    private readonly RateLimitProcessor $processor;

    public function __construct(
        ?CacheHandlerInterface $cacheHandler = null,
        ?RouteResolverInterface $routeResolver = null,
        ?RateLimitConfig $config = null,
        ?LockHandlerInterface $lockHandler = null,
        ?LoggerInterface $logger = null,
        int $maxRetries = 0,
        bool $enableBucketHashDiscovery = false
    ) {
        $resolvedConfig = $config ?? new RateLimitConfig();
        
        $bucketManager = new BucketManager(
            $cacheHandler ?? new ArrayCacheHandler(),
            $routeResolver ?? new DefaultRouteResolver(),
            $resolvedConfig
        );

        $this->processor = new RateLimitProcessor(
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
     * Create a new middleware instance with custom configuration.
     */
    public static function create(
        CacheHandlerInterface $cacheHandler,
        RouteResolverInterface $routeResolver,
        RateLimitConfig $config,
        ?LockHandlerInterface $lockHandler = null,
        ?LoggerInterface $logger = null,
        int $maxRetries = 0,
        bool $enableBucketHashDiscovery = false
    ): static {
        return new static(
            $cacheHandler,
            $routeResolver,
            $config,
            $lockHandler,
            $logger,
            $maxRetries,
            $enableBucketHashDiscovery
        );
    }

    /**
     * Main middleware handler - delegates to the processor.
     */
    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            return $this->processor->process($handler, $request, $options);
        };
    }
}