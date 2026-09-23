# Changelog

## Unreleased

- Run the test suite on PHP 8.2-8.4 (plus lowest dependencies) and PHPStan in GitHub Actions.
- Require PHPUnit 10.1 or later for development; `phpunit.xml.dist` uses the `<source>` element, which 10.0 rejects.

## 1.2.1

- Only look for a global-limit flag in the body of a 429, so ordinary JSON with a `global` field no longer starts a global cooldown.
- Never read a non-seekable response body, so streamed responses reach the caller intact.
- Accept `Retry-After` as an HTTP-date as well as delay-seconds; a date was previously read as "retry immediately".
- Treat a route whose successful responses carry no rate-limit headers as unmetered for a minute, instead of probing it one request at a time under the lock.
- Keep a bucket's known window when a response without rate-limit headers (e.g. a proxy error page) comes back, so spent capacity isn't handed back.
- Key GitHub state by `X-RateLimit-Resource` in `Headers::github()`, so endpoints that share GitHub's `core` (or `search`, `graphql`, …) allowance no longer each believe they have the whole remaining count.
- Scope discovered buckets to the request identity. Two tokens that share a bucket hash (e.g. two Discord bots on the same channel route) no longer share, and exhaust, each other's allowance. Existing bucket state is re-learned after upgrading.
- Record a response's bucket state under the bucket's lock. The merge with the stored window ran unlocked, so a reservation made by another worker in between could be overwritten and its spent slot handed back.
- Run static analysis on PHPStan 2.
- Ship `Testing\FakeClock` and `Testing\RecordingSleeper` so applications can assert rate-limit delays in their own tests without real waits.
- Validate `Options`, `GlobalLimit` and `InvalidRequestLimit` on construction. Out-of-range values (negative retries or delays, jitter outside 0-100, empty windows, non-HTTP status codes) now throw `InvalidArgumentException` instead of misbehaving at request time.
- Accept a PSR-20 clock as the middleware `clock:`, and add `Psr20Clock` to adapt one wherever a `ClockInterface` is expected.
- Add GitLab, Twitch and Reddit presets: `RateLimitMiddleware::gitlab()`, `::twitch()` and `::reddit()`, with matching `Headers` factories.
- Add `FlockLockFactory`, a dependency-free `flock()` lock for workers on one host and the natural partner of `FilesystemStore`.
- Add `SymfonyLockFactory`, an adapter over symfony/lock for locking across hosts (Redis, PDO, …), with a TTL so a crashed worker's lock expires.
- Both take an optional acquire timeout and throw the new `LockTimeoutException` once it passes.
- Stop waiting on the inner handler's promise. Bookkeeping and `429` retries are chained onto it, so `getAsync()`, `Pool` and `Promise\Utils::all()` keep their transfers concurrent. Waiting for an exhausted bucket, a spent global budget or a retry delay still blocks.
- `RateLimitHandler::handle()` always returns a promise. Failures before sending (such as `InvalidRequestLimitExceededException`) reject it instead of being thrown.
- Queue concurrent requests from one process to a bucket with an unknown allowance behind the first response, instead of blocking on the lock that request holds. The lock is also released when that request is cancelled.
- Add `GlobalLimit::$exemptPaths`, path patterns that bypass the global budget, with `GlobalLimit::discord()` and `GlobalLimit::withExemptPaths()`.
- Stop exempting `/interactions/{id}/{token}/callback` from every global limit. This is a behaviour change for non-Discord configurations, which now count those paths unless they list them in `exemptPaths`; `RateLimitMiddleware::discord()` still exempts interaction callbacks, including for a custom `GlobalLimit`.
- Add `InvalidRequestLimit::$ignoredScopes`, the scope values whose 429s don't count against the invalid-request budget (default `['shared']`, as before).
- Date messages for Discord's old-message deletion bucket with the injected clock: `DiscordBucketResolver` accepts a clock, and `RateLimitMiddleware::discord()` takes a `clock` it shares with the resolver.
- Add `Options::withGlobalLimit()`.
- Add pluggable retry strategies: pass a `RetryStrategyInterface` as `retryStrategy:` to decide what is retried, how long to wait, and what throws `RateLimitExceededException`. The default `StatusCodeRetryStrategy` keeps the existing behaviour.
- Add `ExponentialBackoffRetryStrategy`, which backs off with full jitter when a retried response doesn't say how long to wait.
- Add `GitHubRetryStrategy` and use it in `RateLimitMiddleware::github()`: primary and secondary rate limits are retried whether they come as a 403 or a 429, plain 403s are not, and exhausted 403 rate limits throw.

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
