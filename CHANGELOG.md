# Changelog

## Unreleased

- Only look for a global-limit flag in the body of a 429, so ordinary JSON with a `global` field no longer starts a global cooldown.
- Never read a non-seekable response body, so streamed responses reach the caller intact.
- Accept `Retry-After` as an HTTP-date as well as delay-seconds; a date was previously read as "retry immediately".

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
