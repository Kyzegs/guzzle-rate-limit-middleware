<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Config;

use Closure;
use InvalidArgumentException;
use Kyzegs\GuzzleRateLimitMiddleware\Config\GlobalLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\InvalidRequestLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigValidationTest extends TestCase
{
    public function test_defaults_and_presets_are_valid(): void
    {
        $this->assertInstanceOf(Options::class, Options::default());
        $this->assertInstanceOf(Options::class, Options::conservative());
        $this->assertInstanceOf(Options::class, Options::aggressive());
        $this->assertInstanceOf(GlobalLimit::class, new GlobalLimit());
        $this->assertInstanceOf(InvalidRequestLimit::class, new InvalidRequestLimit());
    }

    public function test_boundary_values_are_accepted(): void
    {
        $options = new Options(
            maxRetries: 0,
            safetyBufferSeconds: 0,
            jitterPercent: 100,
            maxStoreTtl: 1,
            retryStatusCodes: [],
            maxDelaySeconds: 0,
        );

        $this->assertSame(0, $options->maxRetries);
        $this->assertSame(0.0, $options->maxDelaySeconds);
    }

    /**
     * @return iterable<string, array{Closure(): mixed, string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'negative retries' => [static fn () => new Options(maxRetries: -1), 'Options::$maxRetries'];
        yield 'negative buffer' => [static fn () => new Options(safetyBufferSeconds: -0.5), 'Options::$safetyBufferSeconds'];
        yield 'jitter above 100' => [static fn () => new Options(jitterPercent: 150), 'Options::$jitterPercent'];
        yield 'negative jitter' => [static fn () => new Options(jitterPercent: -1), 'Options::$jitterPercent'];
        yield 'NaN jitter' => [static fn () => new Options(jitterPercent: NAN), 'Options::$jitterPercent'];
        yield 'zero store TTL' => [static fn () => new Options(maxStoreTtl: 0), 'Options::$maxStoreTtl'];
        yield 'status code out of range' => [static fn () => new Options(retryStatusCodes: [42]), 'Options::$retryStatusCodes'];
        yield 'negative max delay' => [static fn () => new Options(maxDelaySeconds: -1), 'Options::$maxDelaySeconds'];
        yield 'global limit of zero' => [static fn () => new GlobalLimit(maxRequests: 0), 'GlobalLimit::$maxRequests'];
        yield 'global window of zero' => [static fn () => new GlobalLimit(windowSeconds: 0), 'GlobalLimit::$windowSeconds'];
        yield 'invalid limit of zero' => [static fn () => new InvalidRequestLimit(maxRequests: 0), 'InvalidRequestLimit::$maxRequests'];
        yield 'negative invalid window' => [static fn () => new InvalidRequestLimit(windowSeconds: -600), 'InvalidRequestLimit::$windowSeconds'];
        yield 'invalid status code' => [static fn () => new InvalidRequestLimit(statusCodes: [1000]), 'InvalidRequestLimit::$statusCodes'];
    }

    /**
     * @param Closure(): mixed $build
     */
    #[DataProvider('invalidConfigs')]
    public function test_rejects_out_of_range_values(Closure $build, string $field): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($field);

        $build();
    }
}
