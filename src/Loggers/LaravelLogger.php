<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\Loggers;

use Illuminate\Support\Facades\Log;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LoggerInterface;

/**
 * Laravel-compatible logger that uses Laravel's Log facade.
 * Use this when you want to integrate with Laravel's logging system.
 */
class LaravelLogger implements LoggerInterface
{
    public function debug(string $message, array $context = []): void
    {
        Log::debug($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        Log::warning($message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        Log::info($message, $context);
    }
}
