# Changelog

## Unreleased

- Add optional PSR-14 events (`RequestDelayed`, `RetryScheduled`, `RateLimitExceeded`, `RateLimitUpdated`, `InvalidRequestLimitReached`) through a new `events` argument on the middleware, its presets and `RateLimitHandler`.
- Add structured PSR-3 context to log records, and a debug record when the global budget delays a request.
- Require `psr/event-dispatcher` (interfaces only).

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
