![Guzzle Rate Limit Middleware banner](banner.svg)

# Guzzle Rate Limit Middleware

A configurable Guzzle middleware that prevents your application from hitting `429 Too Many Requests` by reading rate-limit response headers and delaying requests *before* they exceed the limit.

State is persisted through a pluggable store, so rate limiting works **across separate requests and processes** — not just within a single operation.

## Features

- 🔧 **Configurable headers** — works with any API (Discord, GitHub, Twitter, the IETF `RateLimit-*` draft, or your own).
- 💾 **Cross-process state** — share rate-limit state via Redis (phpredis or Predis), PSR-16 (Memcached, Laravel/Symfony cache, …), the filesystem, or in-memory.
- ⏳ **Pre-emptive delays** — sleeps until a bucket resets instead of failing.
- 🔁 **429 retries** — honours `Retry-After` and retries up to a configurable limit, then optionally throws.
- 🪣 **Bucket-hash discovery** — adapts to APIs (like Discord) that assign buckets dynamically.
- 🔒 **Optional locking** — plug in a distributed lock to serialise concurrent callers.
- 🧪 **Fully testable** — the clock and sleeper are injectable, so timing is deterministic in tests.

## Installation

```bash
composer require kyzegs/guzzle-rate-limit-middleware
```

Requires PHP 8.2+ and Guzzle 7.10+.

## Quick start

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;

$stack = HandlerStack::create();
$stack->push(new RateLimitMiddleware());

$client = new Client(['handler' => $stack]);
```

The default middleware reads the standard `X-RateLimit-*` headers and keeps state in memory.

### Per-API presets

```php
RateLimitMiddleware::github();   // X-RateLimit-* headers
RateLimitMiddleware::twitter();  // x-rate-limit-* headers
RateLimitMiddleware::ietf();     // RateLimit-* (IETF draft)
RateLimitMiddleware::discord();  // Discord headers + bucket-hash discovery
```

The Discord preset also enables a cross-process 50 request/second global
budget, isolates all state by a one-way authorization fingerprint, accepts the
JSON `retry_after` fallback, and stops at 9,000 invalid requests per 10 minutes
before Discord's Cloudflare threshold. These values are configurable through
`Options`:

```php
use Kyzegs\GuzzleRateLimitMiddleware\Config\GlobalLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\InvalidRequestLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;

$middleware = RateLimitMiddleware::discord(options: new Options(
    globalLimit: new GlobalLimit(maxRequests: 50, windowSeconds: 1),
    invalidRequestLimit: new InvalidRequestLimit(maxRequests: 9000, windowSeconds: 600),
    maxDelaySeconds: 120,
));
```

Raw authorization and webhook tokens never appear in persisted bucket or lock
keys. Interaction callback endpoints are excluded from Discord's bot-global
budget. Shared-scope 429 responses do not consume the invalid-request budget.

## Cross-process rate limiting

To rate limit across separate requests/processes, give the middleware a persistent store. The recommended option is any PSR-16 cache:

```php
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;
use Kyzegs\GuzzleRateLimitMiddleware\Store\Psr16Store;

$middleware = RateLimitMiddleware::github(
    store: new Psr16Store($psr16Cache), // e.g. Redis, Laravel or Symfony cache
);
```

Or use the zero-dependency filesystem store:

```php
use Kyzegs\GuzzleRateLimitMiddleware\Store\FilesystemStore;

$middleware = RateLimitMiddleware::github(
    store: new FilesystemStore('/var/cache/rate-limits'),
);
```

### Available stores

| Store | Cross-process | Notes |
|-------|---------------|-------|
| `InMemoryStore` (default) | ❌ | Lives for the PHP process only. Good for one long-running worker and tests. |
| `FilesystemStore` | ✅ | JSON files with atomic writes. No extra dependencies. |
| `Psr16Store` | ✅ | Wraps any `Psr\SimpleCache\CacheInterface` — Redis, Memcached, Laravel, Symfony, … |
| `RedisStore` | ✅ | phpredis or Predis. Reserves bucket capacity in one atomic Lua script, so buckets need no lock. |

### Redis

`RedisStore` talks to Redis directly, through a phpredis `\Redis` connection or
any Predis client. Unlike the other stores it doesn't need a lock to be safe
across processes: reading a bucket's allowance and taking a slot happen in a
single Lua script on the server, so two workers are never handed the same
slot, and no lock is held while a request is in flight.

```php
use Kyzegs\GuzzleRateLimitMiddleware\Lock\RedisLockFactory;
use Kyzegs\GuzzleRateLimitMiddleware\Store\RedisStore;

$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);
// or: $redis = new \Predis\Client('tcp://127.0.0.1:6379');

$middleware = RateLimitMiddleware::discord(
    store: new RedisStore($redis),
    lockFactory: new RedisLockFactory($redis),
);
```

When a bucket's allowance is unknown (its first request, or the first after a
window resets) the script lets exactly one caller through to learn it and has
the others poll briefly until its response lands. That caller's claim expires
after 10 seconds (`probeTtlSeconds`), so a worker that dies mid-request can't
stall the bucket.

The global and invalid-request budgets (see the Discord preset) still go
through the lock factory. `RedisLockFactory` covers them: `SET NX` locks with
a random token, a TTL (30 seconds by default) for holders that die, an optional
acquire timeout that throws `LockTimeoutException`, and a release that never
frees a lock someone else has taken over since.

Values are JSON, stored under the prefix (`grl:` by default) plus a SHA-1 of
the key. On Redis Cluster, give the store a prefix with a hash tag, such as
`{grl}:`, so the keys a reservation touches share a slot. Redis 6.0 or newer
is required.

## Custom headers

Header names live in the `Headers` config object:

```php
use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;

$headers = new Headers(
    limit:      'X-API-Limit',
    remaining:  'X-API-Remaining',
    reset:      'X-API-Reset',        // absolute timestamp OR relative seconds
    resetAfter: null,                 // relative seconds (preferred when present)
    retryAfter: 'Retry-After',        // used for 429 retry delays
    bucket:     null,                 // enables bucket-hash discovery when set
    global:     null,                 // "true" indicates a global rate limit
    scope:      null,                 // "global" indicates a global rate limit
);

$middleware = RateLimitMiddleware::create(headers: $headers);
```

`reset` values below the year-2000 epoch are treated as relative seconds; larger values as absolute UNIX timestamps.

## Behaviour options

```php
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;

$options = new Options(
    maxRetries:          3,      // retries for a request that keeps getting 429
    safetyBufferSeconds: 1.0,    // added to every computed delay (clock skew/latency)
    jitterPercent:       0.0,    // random extra delay, 0-100% of the base delay
    throwOnRateLimit:    true,   // throw once retries are exhausted on a 429
    maxStoreTtl:         604800, // upper bound for cached bucket state (seconds)
    retryStatusCodes:    [429],  // statuses that trigger a retry
);

$middleware = RateLimitMiddleware::create(options: $options);

// Presets: Options::default(), Options::conservative(), Options::aggressive()
```

When retries are exhausted on a `429` and `throwOnRateLimit` is `true`, a
`Kyzegs\GuzzleRateLimitMiddleware\Exception\RateLimitExceededException` is thrown
(carrying the request, response, retry-after seconds and global flag).

## Bucket resolution

Requests are grouped into buckets that share a rate limit. The default
`DefaultBucketResolver` keys by `METHOD host /path` and collapses
identifier-like path segments — numeric ids/snowflakes, UUIDs, and long hex
tokens — to `{id}` (so `/users/1` and `/users/2`, or two UUIDs, share a bucket).
Human-readable slugs (e.g. `/repos/{owner}/{repo}`) are left literal because
they're indistinguishable from route words; provide a custom resolver for APIs
that bucket on such segments.

Provide your own by implementing `BucketResolverInterface`:

```php
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\BucketResolverInterface;
use Psr\Http\Message\RequestInterface;

final class MyResolver implements BucketResolverInterface
{
    public function resolve(RequestInterface $request): string
    {
        return $request->getMethod() . ' ' . $request->getUri()->getPath();
    }
}

$middleware = RateLimitMiddleware::create(resolver: new MyResolver());
```

### Bucket-hash discovery (Discord)

Some APIs assign a request to a bucket dynamically and report it via a header
(Discord's `X-RateLimit-Bucket`). When `Headers::$bucket` is set, the middleware
stores state under the discovered bucket and re-keys automatically if the API
reassigns a route. `RateLimitMiddleware::discord()` enables this together with a
`DiscordBucketResolver` that respects Discord's major parameters
(`channel_id`, `guild_id`, `webhook_id` and `webhook_token`).

## Concurrency / locking

By default there is no locking. To coordinate concurrent callers that share a
bucket (e.g. multiple workers), pass a lock factory: `RedisLockFactory`, or your
own `LockFactoryInterface`/`LockInterface` implementation:

```php
$middleware = RateLimitMiddleware::create(lockFactory: new MyLockFactory());
```

With `RedisStore` buckets need no lock at all, since the store reserves
capacity atomically; the lock factory is then only used for the global and
invalid-request budgets.

The lock covers the bookkeeping, not the request. A caller takes a slot out of
the bucket's remaining allowance and releases the lock before sending, so a
bucket runs as many requests in parallel as it has capacity for. The lock is
only held across a round trip when the allowance is still unknown — the first
request on a bucket, and the first after a window resets, goes alone because
nothing else can reveal the new limit.

This means a lock wait timeout signals genuine contention on an unknown bucket,
not ordinary throughput.

## Testing your integration

The clock and sleeper are injectable, so you can assert delays without real
waits. See `tests/` — `FakeClock` and `RecordingSleeper` are good starting points.

## Development

```bash
composer test      # PHPUnit
composer analyse   # PHPStan (level 6)
```

## License

MIT License. See [LICENSE](LICENSE).
