# Guzzle Rate Limit Middleware

A configurable Guzzle middleware that provides intelligent rate limiting based on HTTP response headers. This middleware prevents your application from hitting rate limits by automatically delaying requests when necessary.

## Features

- 🚀 **Configurable**: Works with any API that uses standard rate limit headers
- 🔧 **Flexible Cache Handlers**: Built-in support for array and file-based caching, with interface for custom implementations  
- 🎯 **Route Resolution**: Intelligent grouping of requests that share rate limits
- 📦 **Pre-configured**: Ready-to-use configurations for Discord, GitHub, and Twitter APIs
- ⚡ **Zero 429 Responses**: Automatically delays requests to prevent rate limit errors
- 🛡️ **Safeguards**: Built-in safety margins to account for network latency

## Installation

```bash
composer require kyzegs/guzzle-rate-limit-middleware
```

## Quick Start

### Basic Usage

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;

$stack = HandlerStack::create();
$stack->push(new RateLimitMiddleware());

$client = new Client(['handler' => $stack]);
```

### Discord API Example (Advanced Mode)

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;
use Kyzegs\GuzzleRateLimitMiddleware\CacheHandlers\FileCacheHandler;

$stack = HandlerStack::create();
$stack->push(RateLimitMiddleware::discord(new FileCacheHandler()));

$client = new Client([
    'handler' => $stack,
    'base_uri' => 'https://discord.com/api/v10/',
    'headers' => [
        'Authorization' => 'Bot YOUR_BOT_TOKEN',
        'Content-Type' => 'application/json',
    ]
]);

// This request will be automatically rate limited with:
// - Retries on 429 responses
// - Bucket hash discovery
// - Advanced Discord-specific routing
$response = $client->get('users/@me');
```

### GitHub API Example (Simple Mode)

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;
use Kyzegs\GuzzleRateLimitMiddleware\CacheHandlers\FileCacheHandler;

$stack = HandlerStack::create();
$stack->push(RateLimitMiddleware::github(new FileCacheHandler()));

$client = new Client([
    'handler' => $stack,
    'base_uri' => 'https://api.github.com/',
    'headers' => [
        'Authorization' => 'token YOUR_GITHUB_TOKEN',
        'User-Agent' => 'Your-App-Name',
    ]
]);

// Simple rate limiting - just delays requests as needed
$response = $client->get('user');
```

## Advanced Configuration

### Custom Configuration

```php
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;
use Kyzegs\GuzzleRateLimitMiddleware\Configuration\RateLimitConfig;
use Kyzegs\GuzzleRateLimitMiddleware\CacheHandlers\FileCacheHandler;
use Kyzegs\GuzzleRateLimitMiddleware\RouteResolvers\DefaultRouteResolver;

$config = RateLimitConfig::custom([
    'remaining_header' => 'x-custom-remaining',
    'limit_header' => 'x-custom-limit',
    'safeguard_seconds' => 5,
    'respect_retry_after' => true,
    'bucket_hash_header' => 'x-bucket-id',  // Custom bucket hash header
]);

$middleware = RateLimitMiddleware::create(
    cacheHandler: new FileCacheHandler('/tmp/my-cache'),
    routeResolver: new DefaultRouteResolver(),
    config: $config,
    maxRetries: 2,  // Enable retries for advanced mode
    enableBucketHashDiscovery: true  // Enable bucket hash discovery
);

$stack = HandlerStack::create();
$stack->push($middleware);
```

### Custom Cache Handler

```php
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\CacheHandlerInterface;

class RedisCacheHandler implements CacheHandlerInterface
{
    private $redis;
    
    public function __construct($redis)
    {
        $this->redis = $redis;
    }
    
    public function get(string $key): mixed
    {
        $value = $this->redis->get($key);
        return $value ? unserialize($value) : null;
    }
    
    public function put(string $key, mixed $value, int $ttl): bool
    {
        return $this->redis->setex($key, $ttl, serialize($value));
    }
    
    public function has(string $key): bool
    {
        return $this->redis->exists($key);
    }
    
    public function forget(string $key): bool
    {
        return $this->redis->del($key) > 0;
    }
}

// Use with middleware
$middleware = new RateLimitMiddleware(new RedisCacheHandler($redisClient));
```

### Custom Route Resolver

```php
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RouteResolverInterface;
use Psr\Http\Message\RequestInterface;

class CustomRouteResolver implements RouteResolverInterface
{
    public function resolveRouteKey(RequestInterface $request): string
    {
        // Group by API endpoint, ignoring query parameters
        $uri = $request->getUri();
        return sprintf('%s %s://%s%s', $request->getMethod(), $uri->getScheme(), $uri->getHost(), $uri->getPath());
    }
    
    public function extractMajorParameters(RequestInterface $request): string
    {
        // Extract parameters that subdivide rate limit buckets
        // For example, user_id for per-user rate limits
        return '';
    }
    
    public function getFallbackKey(RequestInterface $request): string
    {
        return $this->resolveRouteKey($request);
    }
}

$middleware = new RateLimitMiddleware(
    cacheHandler: new FileCacheHandler(),
    routeResolver: new CustomRouteResolver()
);
```

## Cache Handlers

### ArrayCacheHandler (Default)
- Stores data in memory
- Fast but doesn't persist between requests
- Good for single-request rate limiting

### FileCacheHandler
- Stores data in filesystem
- Persists between requests and application restarts
- Good for most production use cases

```php
use Kyzegs\GuzzleRateLimitMiddleware\CacheHandlers\FileCacheHandler;

// Default temp directory
$handler = new FileCacheHandler();

// Custom cache directory
$handler = new FileCacheHandler('/path/to/cache/directory');
```

## Pre-configured APIs

### Discord
- Uses Discord-specific route resolution for proper bucket handling
- Configured for Discord's rate limit headers
- Handles both `x-ratelimit-reset-after` and `retry-after` headers

### GitHub
- Standard rate limit headers
- 2-second safeguard for API consistency

### Twitter
- Twitter's specific header naming convention
- 3-second safeguard for API reliability

## How It Works

1. **Bucket Resolution**: Groups requests into buckets that share rate limits
2. **Rate Limit Check**: Checks current rate limit state for the bucket
3. **Delay if Needed**: If rate limit is exceeded, delays the request
4. **After Response**: Updates rate limit state from response headers
5. **Cache Storage**: Persists rate limit state for future requests

## Architecture

The middleware uses a **bucket-based rate limiting system** with automatic mode detection:

- **Simple Mode**: Basic rate limiting with delays (no retries, no locking)
- **Advanced Mode**: Full featured with retries, locking, and bucket hash discovery
- **Buckets**: Group requests that share the same rate limit
- **Rate Limit Objects**: Track limit, remaining, and reset time for each bucket  
- **Route Resolvers**: Extract route patterns and major parameters from requests
- **Cache Handlers**: Persist rate limit state between requests

### Mode Detection

The middleware automatically chooses the appropriate mode:

- **Simple Mode** when: Basic usage, no retries, no locking needed
- **Advanced Mode** when: Retries > 0, lock handler provided, or bucket hash discovery enabled

```php
// Simple Mode
RateLimitMiddleware::github()           // No retries
new RateLimitMiddleware()               // Default constructor

// Advanced Mode  
RateLimitMiddleware::discord()          // Has retries + bucket discovery
RateLimitMiddleware::create(..., maxRetries: 3)  // Custom with retries
```

## Rate Limit Headers Supported

The middleware supports various rate limit header formats:

- `x-ratelimit-remaining` / `x-rate-limit-remaining`
- `x-ratelimit-reset` / `x-rate-limit-reset`  
- `x-ratelimit-limit` / `x-rate-limit-limit`
- `retry-after` / `x-ratelimit-reset-after`
- `x-ratelimit-bucket` (configurable bucket hash header)
- `x-ratelimit-global` (configurable global rate limit detection)
- `x-ratelimit-scope` (configurable scope-based global detection)

### Bucket Hash Discovery

For APIs that use dynamic bucket systems (like Discord), you can configure bucket hash discovery:

```php
$config = RateLimitConfig::custom([
    'bucket_hash_header' => 'x-bucket-id',  // Your API's bucket hash header
]);

$middleware = RateLimitMiddleware::create(
    config: $config,
    enableBucketHashDiscovery: true  // Enable the feature
);
```

This allows the middleware to:
- Detect when an API changes bucket assignments
- Clear old rate limit data when buckets change
- Adapt to dynamic bucket reorganization

### Global Rate Limit Detection

For APIs that have global rate limits (like Discord), you can configure header-based detection:

```php
$config = RateLimitConfig::custom([
    'global_rate_limit_header' => 'x-ratelimit-global',  // Discord's global header
    'rate_limit_scope_header' => 'x-ratelimit-scope',    // Discord's scope header
    'global_scope_value' => 'global',                    // Value indicating global scope
]);
```

Detection priority:
1. **Global header**: `X-RateLimit-Global: true`
2. **Scope header**: `X-RateLimit-Scope: global`  
3. **No detection**: Assume not global if no headers configured

### Retry-After Header Configuration

The middleware uses only the configured `retry_after_header` for retry delays:

- **Discord**: Uses `X-RateLimit-Reset-After` (most accurate)
- **GitHub**: Uses `Retry-After` (standard HTTP header)
- **Custom**: Use any header your API provides

## License

MIT License. See [LICENSE](LICENSE) for more information.
