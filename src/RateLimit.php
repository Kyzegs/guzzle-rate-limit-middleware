<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware;

use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\ClockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Support\HeaderParser;
use Psr\Http\Message\ResponseInterface;

/**
 * Immutable snapshot of the rate-limit state for a single bucket.
 *
 * Pure value object: it never sleeps and never touches the wall clock except
 * through the injected {@see ClockInterface}, which keeps it fully testable.
 * It serialises to/from a plain array so it can live in any {@see Contracts\StoreInterface}.
 */
final class RateLimit
{
    /**
     * @param int        $limit      Total requests allowed in the window.
     * @param int        $remaining  Requests left in the current window.
     * @param float      $reset      Absolute UNIX timestamp at which the window resets.
     * @param string|null $bucketHash The API-provided bucket identifier, if any.
     */
    public function __construct(
        public readonly int $limit = 0,
        public readonly int $remaining = 0,
        public readonly float $reset = 0.0,
        public readonly ?string $bucketHash = null,
    ) {
    }

    /**
     * Build a snapshot from a response using the configured header names.
     */
    public static function fromResponse(ResponseInterface $response, Headers $headers, ClockInterface $clock): self
    {
        $now = $clock->now();

        $limit = (int) HeaderParser::value($response, $headers->limit, 0);
        $remaining = (int) HeaderParser::value($response, $headers->remaining, 0);
        $bucketHash = HeaderParser::value($response, $headers->bucket);

        return new self(
            limit: $limit,
            remaining: $remaining,
            reset: self::resolveReset($response, $headers, $now),
            bucketHash: $bucketHash !== null ? (string) $bucketHash : null,
        );
    }

    /**
     * @param array{limit?: int, remaining?: int, reset?: float|int, bucketHash?: string|null} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            limit: (int) ($data['limit'] ?? 0),
            remaining: (int) ($data['remaining'] ?? 0),
            reset: (float) ($data['reset'] ?? 0.0),
            bucketHash: $data['bucketHash'] ?? null,
        );
    }

    /**
     * @return array{limit: int, remaining: int, reset: float, bucketHash: string|null}
     */
    public function toArray(): array
    {
        return [
            'limit' => $this->limit,
            'remaining' => $this->remaining,
            'reset' => $this->reset,
            'bucketHash' => $this->bucketHash,
        ];
    }

    /**
     * Whether a request should wait before being sent: the bucket is exhausted
     * and the window has not yet reset.
     */
    public function shouldDelay(ClockInterface $clock): bool
    {
        return $this->remaining <= 0 && $this->secondsUntilReset($clock) > 0.0;
    }

    /**
     * Seconds until the window resets (never negative).
     */
    public function secondsUntilReset(ClockInterface $clock): float
    {
        return max(0.0, $this->reset - $clock->now());
    }

    /**
     * Resolve the absolute reset timestamp from whichever headers are present,
     * in order of accuracy: relative reset-after, then absolute/relative reset,
     * then retry-after. Returns 0.0 when nothing usable is found.
     */
    private static function resolveReset(ResponseInterface $response, Headers $headers, float $now): float
    {
        $resetAfter = HeaderParser::value($response, $headers->resetAfter);
        if ($resetAfter !== null) {
            return $now + (float) $resetAfter;
        }

        $reset = HeaderParser::value($response, $headers->reset);
        if ($reset !== null) {
            return self::normaliseTimestamp((float) $reset, $now);
        }

        $retryAfter = HeaderParser::value($response, $headers->retryAfter);
        if ($retryAfter !== null) {
            return $now + (float) $retryAfter;
        }

        return 0.0;
    }

    /**
     * Treat values below the year-2000 epoch as relative seconds, otherwise as
     * an absolute UNIX timestamp.
     */
    private static function normaliseTimestamp(float $value, float $now): float
    {
        return $value < 946684800.0 ? $now + $value : $value;
    }
}
