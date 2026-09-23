# Changelog

## Unreleased

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
