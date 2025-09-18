<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\Loggers;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LoggerInterface;

/**
 * Null logger that discards all log messages.
 * Use this when you don't want any logging output.
 */
class NullLogger implements LoggerInterface
{
    public function debug(string $message, array $context = []): void
    {
        // Do nothing
    }

    public function warning(string $message, array $context = []): void
    {
        // Do nothing
    }

    public function info(string $message, array $context = []): void
    {
        // Do nothing
    }
}
