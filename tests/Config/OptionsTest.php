<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Config;

use Kyzegs\GuzzleRateLimitMiddleware\Config\GlobalLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\InvalidRequestLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    public function test_with_global_limit_replaces_only_the_global_limit(): void
    {
        $invalidRequestLimit = new InvalidRequestLimit();
        $original = new Options(
            maxRetries: 7,
            safetyBufferSeconds: 0.5,
            jitterPercent: 5.0,
            throwOnRateLimit: false,
            maxStoreTtl: 120,
            retryStatusCodes: [429, 503],
            globalLimit: new GlobalLimit(),
            invalidRequestLimit: $invalidRequestLimit,
            maxDelaySeconds: 30.0,
        );
        $globalLimit = new GlobalLimit(maxRequests: 5);

        $copy = $original->withGlobalLimit($globalLimit);

        $this->assertSame($globalLimit, $copy->globalLimit);
        $this->assertNotSame($globalLimit, $original->globalLimit, 'The original must be left untouched.');
        $this->assertSame(7, $copy->maxRetries);
        $this->assertSame(0.5, $copy->safetyBufferSeconds);
        $this->assertSame(5.0, $copy->jitterPercent);
        $this->assertFalse($copy->throwOnRateLimit);
        $this->assertSame(120, $copy->maxStoreTtl);
        $this->assertSame([429, 503], $copy->retryStatusCodes);
        $this->assertSame($invalidRequestLimit, $copy->invalidRequestLimit);
        $this->assertSame(30.0, $copy->maxDelaySeconds);
    }
}
