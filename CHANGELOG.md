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
