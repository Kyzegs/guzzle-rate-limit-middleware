# Changelog

## Unreleased

- Add `FlockLockFactory`, a dependency-free `flock()` lock for workers on one host and the natural partner of `FilesystemStore`.
- Add `SymfonyLockFactory`, an adapter over symfony/lock for locking across hosts (Redis, PDO, …), with a TTL so a crashed worker's lock expires.
- Both take an optional acquire timeout and throw the new `LockTimeoutException` once it passes.

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
