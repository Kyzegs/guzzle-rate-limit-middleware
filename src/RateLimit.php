<?php

namespace Kyzegs\GuzzleRateLimitMiddleware;

use Kyzegs\GuzzleRateLimitMiddleware\Configuration\RateLimitConfig;
use Kyzegs\GuzzleRateLimitMiddleware\Utilities\HeaderParser;
use Psr\Http\Message\ResponseInterface;

/**
 * Represents the rate limit state for a specific route/bucket.
 * Based on Discord's rate limiting model but made generic.
 */
class RateLimit
{
    private int $limit = 1;
    private int $remaining = 1;
    private float $resetAfter = 0.0;
    private float $reset = 0.0;
    private bool $dirty = false;

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getRemaining(): int
    {
        return $this->remaining;
    }

    public function getResetAfter(): float
    {
        if ($this->reset > 0.0) {
            return max(0.0, $this->reset - microtime(true));
        }

        return $this->resetAfter;
    }

    public function getReset(): float
    {
        return $this->reset;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /**
     * Reset the rate limit state (when limit resets).
     */
    public function reset(): void
    {
        $this->remaining = $this->limit;
        $this->resetAfter = 0.0;
        $this->dirty = false;
    }

    /**
     * Set a retry-after delay (usually from 429 responses).
     */
    public function retry(float $retryAfter): static
    {
        $this->remaining = 0;
        $this->resetAfter = $retryAfter;
        $this->reset = microtime(true) + $retryAfter;
        $this->dirty = true;

        return $this;
    }

    /**
     * Update rate limit state from response headers.
     */
    public function updateFromResponse(ResponseInterface $response, RateLimitConfig $config): static
    {
        $limit = (int) HeaderParser::getHeaderValue($response, $config->limitHeader, 1);
        $remaining = (int) HeaderParser::getHeaderValue($response, $config->remainingHeader, 0);
        $resetAfter = (float) HeaderParser::getHeaderValue($response, $config->retryAfterHeader, 0.0);
        $reset = HeaderParser::getHeaderValue($response, $config->resetHeader);

        $this->limit = $limit;
        
        if ($reset !== null) {
            $this->reset = $this->parseResetTime((string) $reset);
        }

        if ($this->dirty) {
            $this->remaining = min($remaining, $this->limit);
        } else {
            $this->remaining = $remaining;
            $this->dirty = true;
        }

        if ($resetAfter > 0.0) {
            $this->resetAfter = $resetAfter;
        } elseif ($this->reset > 0.0) {
            $this->resetAfter = max(0.0, $this->reset - microtime(true));
        }

        return $this;
    }

    /**
     * Check if we should delay the next request.
     */
    public function shouldDelay(): bool
    {
        return $this->remaining <= 0 && $this->getResetAfter() > 0;
    }

    /**
     * Get the delay time in seconds.
     */
    public function getDelay(): ?float
    {
        if (!$this->shouldDelay()) {
            return null;
        }

        return $this->getResetAfter();
    }


    private function parseResetTime(string $resetValue): float
    {
        $resetTime = (float) $resetValue;
        
        // If the value is less than a reasonable timestamp (e.g., less than year 2000),
        // assume it's relative seconds from now
        if ($resetTime < 946684800) { // January 1, 2000
            $resetTime = microtime(true) + $resetTime;
        }
        
        return $resetTime;
    }
}
