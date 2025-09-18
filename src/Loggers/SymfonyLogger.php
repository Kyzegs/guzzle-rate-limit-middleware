<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\Loggers;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LoggerInterface;
use Psr\Log\LoggerInterface as PsrLoggerInterface;

/**
 * Symfony-compatible logger that uses any PSR-3 logger.
 * Works with Symfony's logger, Monolog, or any PSR-3 compliant logger.
 */
class SymfonyLogger implements LoggerInterface
{
    public function __construct(
        private readonly PsrLoggerInterface $logger
    ) {}

    public function debug(string $message, array $context = []): void
    {
        $this->logger->debug($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }
}
