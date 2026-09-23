<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Parser;

use InvalidArgumentException;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\ClockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RateLimitParserInterface;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Support\HeaderParser;
use Psr\Http\Message\ResponseInterface;

/**
 * Reads a leaky bucket reported as "used/limit" in a single header, such as
 * Shopify's `X-Shopify-Shop-Api-Call-Limit: 32/40`.
 *
 * {@see RateLimit} models a fixed window: `remaining` requests until `reset`,
 * after which the handler forgets the count and lets one request rediscover
 * it. A leaky bucket has no window — it frees a slot every 1/leakPerSecond
 * seconds — so the reset is chosen to keep that model safe:
 *
 * - While there is room, reset is when the bucket would be completely empty
 *   (`used / leakPerSecond`). Spending only the slots free right now until
 *   then can never overfill it, and by then the full limit really is back.
 *   Slots that leak out in the meantime are left unused, which errs on the
 *   side of never seeing a 429.
 * - When the bucket is full, waiting for it to drain would stall for the
 *   whole bucket (20s for Shopify's 40 at 2/s) when a slot frees after one
 *   leak. So reset is one leak away; the next request then goes alone and
 *   its response shows how full the bucket is.
 *
 * The leak rate is not in the header. Guessing it too low only makes the
 * middleware wait longer than necessary; guessing it too high risks 429s.
 */
final class FractionHeaderParser implements RateLimitParserInterface
{
    /**
     * @param string $header        The header carrying "used/limit".
     * @param float  $leakPerSecond How many requests drain from the bucket per second.
     */
    public function __construct(
        private readonly string $header,
        private readonly float $leakPerSecond = 2.0,
    ) {
        if ($leakPerSecond <= 0.0) {
            throw new InvalidArgumentException('The leak rate must be greater than zero.');
        }
    }

    public function parse(ResponseInterface $response, ClockInterface $clock): ?RateLimit
    {
        $value = HeaderParser::value($response, $this->header);

        if ($value === null || preg_match('~^\s*(\d+)\s*/\s*(\d+)\s*$~', (string) $value, $matches) !== 1) {
            return null;
        }

        $used = (int) $matches[1];
        $limit = (int) $matches[2];

        if ($limit === 0) {
            return null;
        }

        // A full (or, briefly, over-full) bucket frees its next slot once the
        // overflow plus one request has leaked out.
        $drainSeconds = $used >= $limit
            ? ($used - $limit + 1) / $this->leakPerSecond
            : $used / $this->leakPerSecond;

        return new RateLimit(
            limit: $limit,
            remaining: max(0, $limit - $used),
            reset: $clock->now() + $drainSeconds,
        );
    }
}
