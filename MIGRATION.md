# Migration Guide: Replacing Your Discord Middleware

This guide shows how to replace your existing Discord rate limit middleware with this generic package.

## Current vs New Architecture

### Your Current Middleware
```php
// Kyzegs\Laracord\Middleware\RatelimitMiddleware
class RatelimitMiddleware
{
    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $route = $options['laracord_route'] ?? null;
            // ... complex Discord-specific logic
        };
    }
}
```

### New Configurable Middleware
```php
// Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware
$middleware = RateLimitMiddleware::discord(
    cacheHandler: new LaravelCacheHandler(),
    lockHandler: new LaravelLockHandler(),
    logger: new LaravelLogger(),
    maxRetries: 3
);
```

## Step-by-Step Migration

### 1. Install the Package
```bash
composer require kyzegs/guzzle-rate-limit-middleware
```

### 2. Replace Your Middleware Registration

**Before:**
```php
use Kyzegs\Laracord\Middleware\RatelimitMiddleware;

$stack = HandlerStack::create();
$stack->push(new RatelimitMiddleware());
```

**After:**
```php
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;
use Kyzegs\GuzzleRateLimitMiddleware\CacheHandlers\LaravelCacheHandler;
use Kyzegs\GuzzleRateLimitMiddleware\LockHandlers\LaravelLockHandler;
use Kyzegs\GuzzleRateLimitMiddleware\Loggers\LaravelLogger;

$middleware = RateLimitMiddleware::discord(
    cacheHandler: new LaravelCacheHandler(),
    lockHandler: new LaravelLockHandler(),
    logger: new LaravelLogger(),
    maxRetries: 3
);

$stack = HandlerStack::create();
$stack->push($middleware);
```

### 3. Remove Route Options (Optional)

**Before:**
```php
$response = $client->request('GET', 'users/@me', [
    'laracord_route' => $route  // Required by your current middleware
]);
```

**After:**
```php
$response = $client->get('users/@me');  // No special options needed
```

### 4. Keep Your Existing Classes (Recommended)

Your existing classes can stay for other purposes:
- `Route` - Keep for your API client structure
- `Bucket` - Keep if used elsewhere
- `BucketHash` - Keep if used elsewhere
- `Ratelimit` - Keep if used elsewhere

The new middleware is independent and doesn't interfere with your existing code.

## Feature Comparison

| Feature | Your Current | New Advanced | Status |
|---------|-------------|--------------|---------|
| Locking mechanism | ✅ Laravel Cache locks | ✅ Laravel Cache locks | ✅ Same |
| 429 retry logic | ✅ Up to 3 retries | ✅ Configurable retries | ✅ Same/Better |
| Bucket hash discovery | ✅ X-Ratelimit-Bucket | ✅ X-Ratelimit-Bucket | ✅ Same |
| Sub-rate limit detection | ✅ Yes | ✅ Yes | ✅ Same |
| Detailed logging | ✅ Laravel Log | ✅ Laravel Log | ✅ Same |
| Promise-based | ✅ Yes | ✅ Yes | ✅ Same |
| Discord optimization | ✅ Yes | ✅ Yes | ✅ Same |
| **Multi-API support** | ❌ Discord only | ✅ Any API | 🎯 **New!** |
| **Configurable headers** | ❌ Hardcoded | ✅ Configurable | 🎯 **New!** |
| **Testability** | ⚠️ Hard to mock | ✅ Easy to mock | 🎯 **Better!** |
| **Standalone** | ❌ Coupled to Laracord | ✅ Independent | 🎯 **Better!** |

## Benefits of Migration

### 1. 🎯 **Keep All Discord Optimizations**
- Same bucket logic with major parameters
- Same rate limit header handling
- Same retry and locking behavior

### 2. 🔧 **More Flexible & Configurable**
- Easy to test with mock implementations
- Configurable retry counts and timeouts
- Pluggable logging and caching

### 3. 🚀 **Multi-API Support**
```php
// Discord API
$discordMiddleware = RateLimitMiddleware::discord();

// GitHub API  
$githubMiddleware = RateLimitMiddleware::github();
```

### 4. 📦 **Standalone Package**
- Independent versioning
- Easier to maintain
- Can be used in other projects
- Better separation of concerns

### 5. 🧪 **Better Testing**
```php
// Easy to mock for testing
$mockCache = new MockCacheHandler();
$mockLogger = new MockLogger();

$middleware = RateLimitMiddleware::create(
    cacheHandler: $mockCache,
    routeResolver: new DefaultRouteResolver(),
    config: new RateLimitConfig(),
    logger: $mockLogger
);
```

## Gradual Migration Strategy

### Phase 1: Side-by-Side (Recommended)
1. Install the new package
2. Create the new middleware alongside your existing one
3. Test with a subset of requests
4. Compare behavior and performance

### Phase 2: Full Migration
1. Replace middleware registration
2. Remove `laracord_route` option usage
3. Remove old middleware class
4. Keep existing Route/Bucket classes if used elsewhere

### Phase 3: Cleanup (Optional)
1. Remove unused Route/Bucket classes
2. Simplify your Discord client code
3. Consider using the middleware for other APIs

## Troubleshooting

### Issue: "Locking doesn't work"
**Solution:** Make sure Laravel's cache is configured properly:
```php
// In config/cache.php, ensure you're using a shared cache driver:
'default' => 'redis', // or 'database', not 'array' or 'file'
```

### Issue: "Logs not appearing"
**Solution:** Check your Laravel logging configuration:
```php
// The LaravelLogger uses Log::debug(), Log::warning(), etc.
// Make sure your log level allows debug messages
```

### Issue: "Rate limiting seems different"
**Solution:** The new middleware may be more accurate. Check:
1. Same cache driver configuration
2. Same safeguard seconds in config
3. Same retry logic (maxRetries parameter)

## Need Help?

If you encounter issues during migration:
1. Check the examples in the `/examples` directory
2. Compare the feature matrix above
3. Test with basic `RateLimitMiddleware::github()` first, then try `RateLimitMiddleware::discord()` with advanced features

The migration should be seamless with the same rate limiting behavior but better flexibility and maintainability.
