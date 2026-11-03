# Changelog

## 1.3.0

### Upgrading

Nothing is required for code that uses the middleware through a Guzzle client. Worth checking:

- Stored bucket state is re-learned once after upgrading, because `Psr16Store`'s default prefix and Discord's route keys changed. Keep a custom `Psr16Store` prefix free of `{}()/\@:`.
- `RateLimitHandler::handle()` always returns a promise. Code calling it directly must `->wait()`, and failures before sending reject the promise instead of being thrown.
- Log messages and `RateLimitExceededException` no longer contain the URI. They name the method and host, or Discord's route template; a custom resolver opts in to being named with `LoggableRouteInterface`. `getRequest()` still has the whole URI.
- `Options`, `GlobalLimit` and `InvalidRequestLimit` throw `InvalidArgumentException` on out-of-range values instead of misbehaving at request time.
- A `GlobalLimit` no longer exempts `/interactions/{id}/{token}/callback` by itself. `RateLimitMiddleware::discord()` still does; other configurations list such paths in `exemptPaths`.
- `RateLimitMiddleware::github()` retries GitHub's 403 rate limits and throws once they run out; it used to return them.
- Requires `psr/event-dispatcher` (interfaces only).

### Security

- Keep webhook and interaction tokens, and anything else in a URI, out of log messages and `RateLimitExceededException` messages.

### Fixed

- Stop throwing from the middleware instead of rejecting, which hid connection failures from an outer `Middleware::retry` and kept `Pool` sequential. `getAsync()`, `Pool` and `Promise\Utils::all()` now keep their transfers concurrent; waiting for an exhausted bucket, a spent budget or a retry delay still blocks.
- `Psr16Store`'s default prefix used a character PSR-16 reserves, so strict caches such as Symfony's rejected every key.
- A waiter that slept out a window no longer sends straight into a new window another worker has already spent.
- Read reset headers in every format in use. An epoch in milliseconds used to mean a sleep of tens of thousands of years, ISO-8601 read as its year in seconds, and an HTTP-date as no wait.
- Discord: all emoji on a message share one reaction route, and `/guilds/templates/{code}` no longer reads `templates` as a guild id.
- GitHub's rate limits are recognised as a 403 as well as a 429; a plain 403 is still returned without a retry.
- Record a global cooldown under the budget's lock, so a concurrent reservation can't erase it.
- Don't retry a request whose body can't be rewound.
- A lock timeout or store failure while recording a response no longer replaces that response with an exception, which could lead a caller to resend a request that went through. It is logged as a warning.
- Cancelling a request queued behind another's first request to a bucket no longer cancels that other request.
- `InMemoryStore` and `FilesystemStore` purge expired entries, so memory and disk no longer grow without limit.
- Date old-message deletions with the injected clock in `DiscordBucketResolver`.

### Added

- Support Guzzle 8, and `psr/log` and `psr/simple-cache` 1.x and 2.x.
- Presets for GitLab, Twitch, Reddit and Shopify, and `Headers` factories for the first three.
- `RedisStore` (phpredis or Predis), which reserves and records bucket capacity in atomic Lua scripts so buckets need no lock across processes; `AtomicReservationStoreInterface`; `Reservation`.
- `FlockLockFactory` for one host, `SymfonyLockFactory` and `RedisLockFactory` across hosts, with optional acquire timeouts that throw `LockTimeoutException`.
- `RateLimitParserInterface` and a `parser:` argument for limits that don't fit one number per header. `IetfRateLimitParser` reads the draft's structured `RateLimit`/`RateLimit-Policy` fields in `ietf()`; `FractionHeaderParser` reads "used/limit" leaky buckets; `HostBucketResolver` keeps one bucket per host.
- `RetryStrategyInterface` and a `retryStrategy:` argument, with `StatusCodeRetryStrategy` (the default), `ExponentialBackoffRetryStrategy` and `GitHubRetryStrategy`.
- Client-side limits (`Options::$limits`): self-imposed fixed-window, sliding-window and token-bucket budgets for APIs that send no headers.
- A `rate_limit` request option to override `max_delay`, `max_retries`, the bucket or the identity for one request, or bypass the middleware. A named bucket is shared across Discord's major parameters too.
- Optional PSR-14 events (`RequestDelayed`, `RetryScheduled`, `RateLimitExceeded`, `RateLimitUpdated`, `InvalidRequestLimitReached`) and structured PSR-3 log context.
- `RateLimitMiddleware::state()`, `forget()`, `globalBudget()` and `invalidRequestBudget()`, `BudgetState`, `InspectableHandlerInterface` and `RateLimit::resetsAt()`.
- `GlobalLimit::$exemptPaths`, `GlobalLimit::discord()`, `GlobalLimit::withExemptPaths()`, `InvalidRequestLimit::$ignoredScopes` and `Options::withGlobalLimit()`.
- PSR-20 clocks as `clock:`, and `Psr20Clock` wherever a `ClockInterface` is expected.
- `Testing\FakeClock` and `Testing\RecordingSleeper`, for asserting delays in your own tests.
- Every preset takes the same optional store, logger, lock factory, options and events.

### Internal

- CI on PHP 8.2-8.5, Linux, Windows and macOS, Guzzle 7 and 8, lowest dependencies, PHPStan 2 and a dependency audit.
- PHPUnit 11. Tests, examples and tooling config no longer ship in the Composer package.

## 1.2.1

- Only look for a global-limit flag in the body of a 429, so ordinary JSON with a `global` field no longer starts a global cooldown.
- Never read a non-seekable response body, so streamed responses reach the caller intact.
- Accept `Retry-After` as an HTTP-date as well as delay-seconds; a date was previously read as "retry immediately".
- Treat a route whose successful responses carry no rate-limit headers as unmetered for a minute, instead of probing it one request at a time under the lock.
- Keep a bucket's known window when a response without rate-limit headers (e.g. a proxy error page) comes back, so spent capacity isn't handed back.
- Key GitHub state by `X-RateLimit-Resource` in `Headers::github()`, so endpoints that share GitHub's `core` (or `search`, `graphql`, …) allowance no longer each believe they have the whole remaining count.
- Scope discovered buckets to the request identity. Two tokens that share a bucket hash (e.g. two Discord bots on the same channel route) no longer share, and exhaust, each other's allowance. Existing bucket state is re-learned after upgrading.
- Record a response's bucket state under the bucket's lock. The merge with the stored window ran unlocked, so a reservation made by another worker in between could be overwritten and its spent slot handed back.

## 1.2.0

- Reserve bucket capacity under the lock instead of holding it across the request, so a bucket runs as many requests in parallel as it has remaining.
- Keep the lowest `remaining` seen within a window, so out-of-order responses cannot hand back spent capacity.

## 1.1.0

- Add credential-isolated Discord bucket state with opaque cache and lock keys.
- Add configurable cross-process global request budgets.
- Add configurable invalid-request safety budgets.
- Parse Discord JSON `retry_after` while preserving response streams.
- Persist global 429 cooldowns across routes and processes.
- Distinguish old-message deletion buckets and interaction callbacks.
- Add configurable maximum rate-limit delay.

## 1.0.0

- Initial stable release.
