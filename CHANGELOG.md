# Changelog

## Unreleased

- Add `RateLimitParserInterface` and a `parser:` option, for APIs whose rate limit doesn't fit one number per header. A parser returning `null` leaves the stored state untouched.
- Read the IETF draft's structured `RateLimit`/`RateLimit-Policy` fields (current list form and older dictionary form) in `RateLimitMiddleware::ietf()`, tracking the policy that runs out first. The separate `RateLimit-*` headers still work.
- Add `FractionHeaderParser` for "used/limit" leaky-bucket headers, `HostBucketResolver`, and a `RateLimitMiddleware::shopify()` preset.

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
