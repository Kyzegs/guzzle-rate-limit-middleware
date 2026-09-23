# Changelog

## Unreleased

- Add `RedisStore` (phpredis or Predis), which reserves bucket capacity in a single atomic Lua script, so buckets need no lock across processes and none is held while a request is in flight.
- Add `AtomicReservationStoreInterface`, which the handler uses in place of the bucket lock when a store implements it.
- Add `RedisLockFactory`: token-checked Redis locks with a TTL and an optional acquire timeout that throws `LockTimeoutException`.

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
