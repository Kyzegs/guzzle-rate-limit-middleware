<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Parser;

use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\ClockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RateLimitParserInterface;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Support\HeaderParser;
use Kyzegs\GuzzleRateLimitMiddleware\Support\StructuredFieldParser;
use Psr\Http\Message\ResponseInterface;

/**
 * Reads the rate-limit fields of the IETF draft
 * (draft-ietf-httpapi-ratelimit-headers) across the shapes it has had:
 *
 * - Current: a list of named policies with their remaining quota (`r`) and
 *   seconds until reset (`t`), with the quota itself (`q`) and window (`w`)
 *   in a separate `RateLimit-Policy` field:
 *
 *       RateLimit-Policy: "burst";q=100;w=60, "daily";q=1000;w=86400
 *       RateLimit: "burst";r=50;t=30, "daily";r=950;t=5000
 *
 * - Older: a single dictionary, `RateLimit: limit=100, remaining=50, reset=30`.
 * - Oldest: separate `RateLimit-Limit`/`-Remaining`/`-Reset` headers, read by
 *   {@see HeaderRateLimitParser} with {@see Headers::ietf()} whenever there
 *   is no `RateLimit` field at all.
 *
 * The middleware keeps one allowance per bucket, so when several policies
 * apply it tracks the one that runs out first: the lowest remaining quota,
 * and between equally spent policies the one that resets last.
 *
 * A `RateLimit` field that cannot be parsed yields null, so a server bug
 * leaves the known state alone instead of replacing it with a guess.
 */
final class IetfRateLimitParser implements RateLimitParserInterface
{
    private readonly HeaderRateLimitParser $legacy;

    public function __construct()
    {
        $this->legacy = new HeaderRateLimitParser(Headers::ietf());
    }

    public function parse(ResponseInterface $response, ClockInterface $clock): ?RateLimit
    {
        $field = HeaderParser::value($response, 'RateLimit');

        if ($field === null) {
            return $this->legacy->parse($response, $clock);
        }

        $now = $clock->now();

        return $this->fromPolicyList((string) $field, $response, $now)
            ?? $this->fromDictionary((string) $field, $response, $now);
    }

    private function fromPolicyList(string $field, ResponseInterface $response, float $now): ?RateLimit
    {
        $members = StructuredFieldParser::parseList($field);

        if ($members === null) {
            return null;
        }

        $policies = $this->policies($response);
        $chosen = null;

        foreach ($members as [$name, $parameters]) {
            $remaining = $parameters['r'] ?? null;

            if (! is_int($remaining)) {
                continue;
            }

            $policy = is_string($name) ? ($policies[$name] ?? []) : [];

            // Byte or concurrency quotas don't count requests; treating their
            // numbers as request slots would throttle for the wrong reason.
            if (($policy['qu'] ?? 'requests') !== 'requests') {
                continue;
            }

            $quota = $policy['q'] ?? null;
            $candidate = new RateLimit(
                limit: is_int($quota) ? max(0, $quota) : 0,
                remaining: max(0, $remaining),
                reset: $this->reset($parameters['t'] ?? null, $policy['w'] ?? null, $response, $now),
            );

            if ($chosen === null || $this->runsOutFirst($candidate, $chosen)) {
                $chosen = $candidate;
            }
        }

        return $chosen;
    }

    private function fromDictionary(string $field, ResponseInterface $response, float $now): ?RateLimit
    {
        $dictionary = StructuredFieldParser::parseDictionary($field);
        $remaining = $dictionary['remaining'][0] ?? null;

        if (! is_int($remaining)) {
            return null;
        }

        $limit = $dictionary['limit'][0] ?? null;

        return new RateLimit(
            limit: is_int($limit) ? max(0, $limit) : 0,
            remaining: max(0, $remaining),
            reset: $this->reset($dictionary['reset'][0] ?? null, null, $response, $now),
        );
    }

    /**
     * The `RateLimit-Policy` members by name. The field only adds the limit
     * and window, so a malformed one is ignored rather than discarding the
     * remaining quota that `RateLimit` did report.
     *
     * @return array<string, array<string, int|float|string|bool>>
     */
    private function policies(ResponseInterface $response): array
    {
        $field = HeaderParser::value($response, 'RateLimit-Policy');
        $members = $field === null ? null : StructuredFieldParser::parseList((string) $field);
        $policies = [];

        foreach ($members ?? [] as [$name, $parameters]) {
            if (is_string($name) && ! isset($policies[$name])) {
                $policies[$name] = $parameters;
            }
        }

        return $policies;
    }

    /**
     * Seconds until reset are optional in the current draft. Without them the
     * policy window is a safe upper bound — whatever has been used comes back
     * within one window — and failing that, Retry-After.
     */
    private function reset(mixed $resetAfter, mixed $window, ResponseInterface $response, float $now): float
    {
        foreach ([$resetAfter, $window] as $seconds) {
            if (is_int($seconds) || is_float($seconds) || (is_string($seconds) && is_numeric($seconds))) {
                return $now + max(0.0, (float) $seconds);
            }
        }

        $retryAfter = HeaderParser::delay($response, 'Retry-After', $now);

        return $retryAfter !== null ? $now + $retryAfter : 0.0;
    }

    private function runsOutFirst(RateLimit $candidate, RateLimit $chosen): bool
    {
        return $candidate->remaining < $chosen->remaining
            || ($candidate->remaining === $chosen->remaining && $candidate->reset > $chosen->reset);
    }
}
