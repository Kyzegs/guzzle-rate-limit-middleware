# Changelog

## Unreleased

- Stop waiting on the inner handler's promise. Bookkeeping and `429` retries are chained onto it, so `getAsync()`, `Pool` and `Promise\Utils::all()` keep their transfers concurrent. Waiting for an exhausted bucket, a spent global budget or a retry delay still blocks.
- `RateLimitHandler::handle()` always returns a promise. Failures before sending (such as `InvalidRequestLimitExceededException`) reject it instead of being thrown.
- Queue concurrent requests from one process to a bucket with an unknown allowance behind the first response, instead of blocking on the lock that request holds. The lock is also released when that request is cancelled.

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
