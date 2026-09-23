# Changelog

## Unreleased

- Add `GlobalLimit::$exemptPaths`, path patterns that bypass the global budget, with `GlobalLimit::discord()` and `GlobalLimit::withExemptPaths()`.
- Stop exempting `/interactions/{id}/{token}/callback` from every global limit. This is a behaviour change for non-Discord configurations, which now count those paths unless they list them in `exemptPaths`; `RateLimitMiddleware::discord()` still exempts interaction callbacks, including for a custom `GlobalLimit`.
- Add `InvalidRequestLimit::$ignoredScopes`, the scope values whose 429s don't count against the invalid-request budget (default `['shared']`, as before).
- Date messages for Discord's old-message deletion bucket with the injected clock: `DiscordBucketResolver` accepts a clock, and `RateLimitMiddleware::discord()` takes a `clock` it shares with the resolver.
- Add `Options::withGlobalLimit()`.

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
