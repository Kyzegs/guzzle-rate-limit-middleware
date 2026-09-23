![Guzzle Rate Limit Middleware banner](banner.svg)

# Guzzle Rate Limit Middleware

A configurable Guzzle middleware that prevents your application from hitting `429 Too Many Requests` by reading rate-limit response headers and delaying requests *before* they exceed the limit.

State is persisted through a pluggable store, so rate limiting works **across separate requests and processes** — not just within a single operation.

## Features

- 🔧 **Configurable headers and parsers** — works with any API (Discord, GitHub, GitLab, Twitch, Reddit, Twitter, Shopify, the IETF `RateLimit` draft, or your own).
- 💾 **Cross-process state** — share rate-limit state via PSR-16 (Redis, Memcached, Laravel/Symfony cache), the filesystem, or in-memory.
- ⏳ **Pre-emptive delays** — sleeps until a bucket resets instead of failing.
- 🔁 **429 retries** — honours `Retry-After` and retries up to a configurable limit, then optionally throws; pluggable strategies add backoff and GitHub's 403 rate limits.
- 🚦 **Client-side limits** — your own budgets (e.g. 10/s *and* 5,000/h) for APIs that send no headers.
- 🪣 **Bucket-hash discovery** — adapts to APIs (like Discord) that assign buckets dynamically.
- 🔒 **Cross-process locking** — `flock()` on one host or symfony/lock (Redis, PDO, …) across hosts, or plug in your own.
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
RateLimitMiddleware::github();   // X-RateLimit-* headers, one bucket per X-RateLimit-Resource, GitHub's 403/429 retry rules
RateLimitMiddleware::twitter();  // x-rate-limit-* headers
RateLimitMiddleware::ietf();     // RateLimit / RateLimit-Policy (IETF draft), or legacy RateLimit-*
RateLimitMiddleware::gitlab();   // RateLimit-* with an absolute reset
RateLimitMiddleware::twitch();   // Ratelimit-* (Helix)
RateLimitMiddleware::reddit();   // X-Ratelimit-* with a relative reset
RateLimitMiddleware::shopify();  // X-Shopify-Shop-Api-Call-Limit leaky bucket
RateLimitMiddleware::discord();  // Discord headers + bucket-hash discovery
```

The IETF preset understands the draft's structured fields — the current
`RateLimit: "default";r=50;t=30` with its `RateLimit-Policy`, the older
`RateLimit: limit=100, remaining=50, reset=30` — as well as the separate
`RateLimit-Limit`/`-Remaining`/`-Reset` headers. When a response lists several
policies, it tracks whichever runs out first.

The Shopify preset reads `X-Shopify-Shop-Api-Call-Limit: 32/40`, keeps one
bucket per shop (every REST endpoint drains the same one), and retries a `429`
after its `Retry-After`. It assumes the standard 2 requests/second leak rate,
which only errs on the slow side for faster plans.

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
budget; the preset adds that exemption to any `GlobalLimit` you pass, so the
example above keeps it (`GlobalLimit::discord()` spells it out). Shared-scope
429 responses do not consume the invalid-request budget
(`InvalidRequestLimit::$ignoredScopes`, default `['shared']`).

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
    retryAfter: 'Retry-After',        // used for 429 retry delays (seconds or HTTP-date)
    bucket:     null,                 // enables bucket-hash discovery when set
    global:     null,                 // "true" indicates a global rate limit
    scope:      null,                 // "global" indicates a global rate limit
);

$middleware = RateLimitMiddleware::create(headers: $headers);
```

`reset` values below the year-2000 epoch are treated as relative seconds; larger values as absolute UNIX timestamps.

## Custom parsers

`Headers` feeds the default `HeaderRateLimitParser`, which fits APIs that send
one number per header. For anything else, swap the parser. The library also
ships `IetfRateLimitParser` (used by `ietf()`) and `FractionHeaderParser` for
"used/limit" leaky buckets:

```php
use Kyzegs\GuzzleRateLimitMiddleware\Parser\FractionHeaderParser;

$middleware = RateLimitMiddleware::create(
    parser: new FractionHeaderParser('X-Api-Call-Limit', leakPerSecond: 4.0),
);
```

Or implement `RateLimitParserInterface`. Return `null` when a response carries
no rate-limit information; the stored state is then left as it is:

```php
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\ClockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RateLimitParserInterface;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimit;
use Psr\Http\Message\ResponseInterface;

// X-Quota: 12 left, resets in 30s
final class QuotaParser implements RateLimitParserInterface
{
    public function parse(ResponseInterface $response, ClockInterface $clock): ?RateLimit
    {
        if (preg_match('/^(\d+) left, resets in (\d+)s$/', $response->getHeaderLine('X-Quota'), $m) !== 1) {
            return null;
        }

        return new RateLimit(
            limit: 0,                             // unknown
            remaining: (int) $m[1],
            reset: $clock->now() + (int) $m[2],   // absolute UNIX timestamp
        );
    }
}

$middleware = RateLimitMiddleware::create(parser: new QuotaParser());
```

A parser only replaces how limit, remaining and reset are read. `Headers`
still names the `Retry-After`, bucket, global and scope headers.

## Behaviour options

```php
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;

$options = new Options(
    maxRetries:          3,      // retries for a request that keeps getting 429
    safetyBufferSeconds: 1.0,    // added to every computed delay (clock skew/latency)
    jitterPercent:       0.0,    // random extra delay, 0-100% of the base delay
    throwOnRateLimit:    true,   // throw once retries are exhausted on a rate limit (a 429 by default)
    maxStoreTtl:         604800, // upper bound for cached bucket state (seconds)
    retryStatusCodes:    [429],  // statuses that trigger a retry
);

$middleware = RateLimitMiddleware::create(options: $options);

// Presets: Options::default(), Options::conservative(), Options::aggressive()
```

`maxRetries` and `retryStatusCodes` configure the default retry strategy; see
[Retry strategies](#retry-strategies) to replace it.

Out-of-range values (negative retries, jitter outside 0-100, a zero-length
window, …) throw an `InvalidArgumentException` when the config is built.

When retries are exhausted on a rate limit (a `429`, unless the retry strategy
says otherwise) and `throwOnRateLimit` is `true`, a
`Kyzegs\GuzzleRateLimitMiddleware\Exception\RateLimitExceededException` is thrown
(carrying the request, response, retry-after seconds and global flag); for an
async request, the promise rejects with it. Any other status is returned as is
once its retries run out.

### Global request budget

`globalLimit` caps every request made with one credential, across all routes,
on top of the per-bucket limits. Routes the API leaves out of that budget are
exempted with regular expressions matched against the request path:

```php
use Kyzegs\GuzzleRateLimitMiddleware\Config\GlobalLimit;

$options = new Options(
    globalLimit: new GlobalLimit(
        maxRequests:   100,
        windowSeconds: 60,
        exemptPaths:   ['#^/health$#'],
    ),
);
```

Nothing is exempt by default, and a pattern that doesn't compile throws an
`InvalidArgumentException` when the limit is built.

## Retry strategies

Whether a response is retried, how long to wait first, and whether it counts as
a rate limit is up to a `RetryStrategyInterface`. The default,
`StatusCodeRetryStrategy`, retries `retryStatusCodes` up to `maxRetries` times
and waits as long as `Retry-After` (or the bucket reset) says.

When a response says nothing, that wait is just the safety buffer. Wrap the
strategy in `ExponentialBackoffRetryStrategy` to back off instead (a wait the
response does give is left alone):

```php
use Kyzegs\GuzzleRateLimitMiddleware\Retry\ExponentialBackoffRetryStrategy;
use Kyzegs\GuzzleRateLimitMiddleware\Retry\StatusCodeRetryStrategy;

$middleware = RateLimitMiddleware::create(
    retryStrategy: new ExponentialBackoffRetryStrategy(
        new StatusCodeRetryStrategy(maxRetries: 5, statusCodes: [429, 503]),
        baseSeconds: 1.0,  // up to 1s, 2s, 4s, … (full jitter)
        maxSeconds:  60.0,
    ),
);
```

`RateLimitMiddleware::github()` uses `GitHubRetryStrategy`, since GitHub
answers a rate limit with a `403` as readily as a `429`:

- `x-ratelimit-remaining: 0` (primary limit) waits until `x-ratelimit-reset`;
- `retry-after` (secondary limit) waits that long;
- a secondary limit that gives neither waits a minute, then doubles;
- any other `403` is taken as a permissions error and returned without a retry.

Exhausted `403` rate limits throw `RateLimitExceededException` like a `429`. To
change the number of retries, build it yourself:

```php
use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\Retry\GitHubRetryStrategy;

$middleware = RateLimitMiddleware::create(
    headers: Headers::github(),
    retryStrategy: new GitHubRetryStrategy(maxRetries: 5),
);
```

For anything else, implement `RetryStrategyInterface`: return the seconds to
wait (or `null` to stop) from `retryDelay()`, and say in `isRateLimited()`
which responses should throw once retries stop. The safety buffer, jitter and
`maxDelaySeconds` still apply on top.

## Client-side limits

Some APIs document their limits but send no headers, many enforce several at
once, and sometimes you just want to stay well under a provider's ceiling.
Client-side limits are budgets you declare yourself, enforced before every
request whether or not the API reports anything:

```php
use Kyzegs\GuzzleRateLimitMiddleware\Config\Limit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;
use Kyzegs\GuzzleRateLimitMiddleware\Store\Psr16Store;
use Psr\Http\Message\RequestInterface;

// 10 requests/second and 5,000/hour per API key, plus 30 searches/minute.
$middleware = RateLimitMiddleware::create(
    store: new Psr16Store($psr16Cache),
    options: new Options(
        safetyBufferSeconds: 0.05,
        limits: [
            Limit::perSecond(10),
            Limit::perHour(5000),
            Limit::perMinute(
                30,
                appliesTo: fn (RequestInterface $request): bool => $request->getUri()->getPath() === '/v1/search',
                name: 'search',
            ),
        ],
    ),
);
```

A request waits until every limit that applies to it has room, sleeping once
for the longest wait, and takes nothing from any of them until it can take
from all: a request waiting out the hourly budget never holds a slot of the
per-second one.

| Algorithm | Behaviour |
|-----------|-----------|
| `SlidingWindow` (default) | Counts the previous window in proportion to how much of it still overlaps, smoothing out bursts at window edges. |
| `FixedWindow` | `maxRequests` per window, opened by the first request. Simplest, but allows up to twice the budget across a window edge. |
| `TokenBucket` | Bursts up to `maxRequests`, then refills `maxRequests` per window continuously. |

| Scope | One budget shared by |
|-------|----------------------|
| `Identity` (default) | requests with the same credentials |
| `Host` | requests with the same credentials to the same host |
| `Route` | requests with the same credentials to the same route, as the bucket resolver groups them |
| `Everything` | every request, whatever its credentials |

Build limits with `new Limit($maxRequests, $windowSeconds, $algorithm, $scope, $appliesTo, $name)`,
or the `Limit::perSecond()`, `perMinute()`, `perHour()` and `perDay()` shorthands.

Waits get the same safety buffer and jitter as header-driven ones and respect
`maxDelaySeconds`, so keep `safetyBufferSeconds` small for per-second budgets.
State lives in the configured store under hashed keys, so processes sharing a
store share the budgets; add a lock factory if they run concurrently. Limits
that differ only in `appliesTo` need distinct names, or they share one budget.

## Per-request options

Adjust or bypass the middleware for a single call with the `rate_limit`
request option:

```php
$client->get('/search', ['rate_limit' => ['max_delay' => 0]]);        // fail fast instead of sleeping
$client->post('/bulk', ['rate_limit' => ['bucket' => 'bulk-writes']]);  // share one bucket across routes
$client->get('/health', ['rate_limit' => ['enabled' => false]]);        // bypass the middleware entirely

// per-tenant state; give up on the first 429
$client->get('/items', ['rate_limit' => ['identity' => 'tenant-42', 'max_retries' => 0]]);
```

| Key | Type | Effect |
|-----|------|--------|
| `enabled` | `bool` | `false` sends the request straight through: no waiting, retries, budgets or stored state. |
| `bucket` | `string` | Replaces the resolved bucket, so different routes can share one limit. Still scoped by identity. |
| `identity` | `string` | Replaces the resolved identity (the `Authorization` fingerprint by default), e.g. with a tenant id. Fingerprinted the same way, never stored raw. |
| `max_delay` | number or `null` | Overrides `Options::$maxDelaySeconds`. `0` throws `RateLimitDelayExceededException` instead of waiting; `null` lifts the cap. |
| `max_retries` | `int` | Overrides `Options::$maxRetries`. |

Unknown keys, wrong types and negative numbers throw an `InvalidArgumentException`,
and the option is removed before the request reaches the next handler. Set it in
the client config (`new Client(['handler' => $stack, 'rate_limit' => [...]])`) to
apply it to every request; a per-request `rate_limit` then replaces it as a whole,
since Guzzle merges options shallowly.

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

`RateLimitMiddleware::github()` uses the same mechanism with
`X-RateLimit-Resource`, so every endpoint in a resource (`core`, `search`,
`graphql`, …) shares one allowance per token, as GitHub counts it.

## Concurrency / locking

By default there is no locking, which is fine for a single process. Once
several processes share a persistent store, give them a lock factory too, or
two workers can read the same allowance and both spend it.

On one host, pair `FilesystemStore` with `FlockLockFactory`. It has no
dependencies, and the OS drops a lock the moment its holder exits, so a crashed
worker never leaves a bucket locked:

```php
use Kyzegs\GuzzleRateLimitMiddleware\Lock\FlockLockFactory;
use Kyzegs\GuzzleRateLimitMiddleware\Store\FilesystemStore;

$middleware = RateLimitMiddleware::create(
    store: new FilesystemStore('/var/cache/rate-limits'),
    lockFactory: new FlockLockFactory('/var/cache/rate-limits/locks'),
);
```

`flock()` only coordinates processes on the same machine, and is unreliable on
NFS. Across hosts, use `SymfonyLockFactory` over a shared
[symfony/lock](https://symfony.com/doc/current/components/lock.html) store such
as Redis or PDO (`composer require symfony/lock`):

```php
use Kyzegs\GuzzleRateLimitMiddleware\Lock\SymfonyLockFactory;
use Kyzegs\GuzzleRateLimitMiddleware\Store\Psr16Store;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\RedisStore;

$middleware = RateLimitMiddleware::create(
    store: new Psr16Store($psr16Cache),
    lockFactory: new SymfonyLockFactory(
        new LockFactory(new RedisStore($redis)),
        ttlSeconds: 30.0, // must outlast your slowest request
    ),
);
```

For anything else, implement `LockFactoryInterface`/`LockInterface`.

The lock covers the bookkeeping, not the request. A caller takes a slot out of
the bucket's remaining allowance and releases the lock before sending, so a
bucket runs as many requests in parallel as it has capacity for. It takes the
lock again briefly to record the response. The lock is
only held across a round trip when the allowance is still unknown — the first
request on a bucket, and the first after a window resets, goes alone because
nothing else can reveal the new limit. A route whose successful responses carry
no rate-limit headers is treated as unmetered for a minute, so its requests
don't queue behind a probe that will never learn anything.

Both lock settings have to allow for that round trip:

- **TTL** (`SymfonyLockFactory` only). A lock in Redis or a database outlives a
  worker that crashes while holding it, so it expires after `ttlSeconds`
  (default 30). Keep it above your Guzzle `timeout`: if it lapses mid-request,
  a second worker may probe the same bucket and draw a 429. `flock()` needs no
  TTL, since the OS releases the lock with the process.
- **Timeout** (both factories). `acquire()` waits indefinitely by default. Pass
  `timeoutSeconds` to throw a
  `Kyzegs\GuzzleRateLimitMiddleware\Exception\LockTimeoutException` instead,
  and make it at least as long as your slowest request, because a waiter may
  queue behind a probe. A timeout then signals genuine contention on an unknown
  bucket, not ordinary throughput.

## Async requests

The middleware never waits on the transfer itself, so `getAsync()`, `Pool` and
`Promise\Utils::all()` keep their requests in flight together:

```php
use GuzzleHttp\Promise\Utils;

$responses = Utils::all([
    $client->getAsync('https://api.github.com/users/octocat'),
    $client->getAsync('https://api.github.com/users/torvalds'),
])->wait();
```

Transfers run concurrently; waiting for an exhausted bucket still blocks. The
wait for a spent bucket or global budget, and the delay before retrying a `429`,
go through the (blocking) sleeper: each has to re-read the shared state once the
time is up, and without an event loop there is nothing to schedule that on.

Within one process, requests to a bucket whose allowance is still unknown wait
for the first response to reveal it, then go out together. Rate-limit errors
reject the returned promise instead of being thrown from `getAsync()`.

## Testing your integration

The clock and sleeper are injectable, so you can assert delays without real
waits. The package ships the two test doubles it uses itself:

```php
use GuzzleHttp\Handler\MockHandler;
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Testing\FakeClock;
use Kyzegs\GuzzleRateLimitMiddleware\Testing\RecordingSleeper;

$clock = new FakeClock(1_700_000_000.0);
$sleeper = new RecordingSleeper($clock); // advances the clock instead of blocking

$stack = HandlerStack::create(new MockHandler([/* responses */]));
$stack->push(RateLimitMiddleware::create(
    store: new InMemoryStore($clock),
    clock: $clock,
    sleeper: $sleeper,
));

// ... send requests, then:
$sleeper->sleeps;  // every delay requested, in seconds
$sleeper->total();
```

The `clock:` argument also accepts any PSR-20 clock (e.g. `symfony/clock`), so
the middleware can share your application's clock. Wrap one in `Psr20Clock`
where a `ClockInterface` is expected, such as the stores:

```php
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Support\Psr20Clock;

$middleware = RateLimitMiddleware::create(
    store: new InMemoryStore(new Psr20Clock($psrClock)),
    clock: $psrClock,
);
```

## Development

```bash
composer test      # PHPUnit
composer analyse   # PHPStan (level 8)
```

## License

MIT License. See [LICENSE](LICENSE).
