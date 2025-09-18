<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

interface LoggerInterface
{
    /**
     * Log a debug message.
     */
    public function debug(string $message, array $context = []): void;

    /**
     * Log a warning message.
     */
    public function warning(string $message, array $context = []): void;

    /**
     * Log an info message.
     */
    public function info(string $message, array $context = []): void;
}
