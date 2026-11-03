<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Config;

use InvalidArgumentException;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\Config\RequestOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestOptionsTest extends TestCase
{
    public function test_absent_option_resolves_to_the_configured_behaviour(): void
    {
        $defaults = new Options(maxRetries: 4, maxDelaySeconds: 30.0);

        $options = RequestOptions::fromArray(null, $defaults);

        $this->assertTrue($options->enabled);
        $this->assertNull($options->bucket);
        $this->assertNull($options->identity);
        $this->assertSame(30.0, $options->maxDelaySeconds);
        $this->assertSame(4, $options->maxRetries);
        $this->assertEquals($options, RequestOptions::fromArray([], $defaults));
    }

    public function test_reads_every_key(): void
    {
        $options = RequestOptions::fromArray([
            'enabled' => false,
            'bucket' => 'bulk-writes',
            'identity' => 'tenant-42',
            'max_delay' => 2.5,
            'max_retries' => 0,
        ], new Options(maxRetries: 4, maxDelaySeconds: 30.0));

        $this->assertFalse($options->enabled);
        $this->assertSame('bulk-writes', $options->bucket);
        $this->assertSame('tenant-42', $options->identity);
        $this->assertSame(2.5, $options->maxDelaySeconds);
        $this->assertSame(0, $options->maxRetries);
    }

    public function test_max_delay_accepts_integers_and_null_lifts_the_cap(): void
    {
        $defaults = new Options(maxDelaySeconds: 30.0);

        $this->assertSame(0.0, RequestOptions::fromArray(['max_delay' => 0], $defaults)->maxDelaySeconds);
        $this->assertNull(RequestOptions::fromArray(['max_delay' => null], $defaults)->maxDelaySeconds);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidOptions(): array
    {
        $bucket = 'The "rate_limit.bucket" request option must be a non-empty string';
        $identity = 'The "rate_limit.identity" request option must be a non-empty string';
        $maxDelay = 'The "rate_limit.max_delay" request option must be a non-negative number or null';
        $maxRetries = 'The "rate_limit.max_retries" request option must be a non-negative integer';

        return [
            'not an array' => ['fast', 'The "rate_limit" request option must be an array, got string.'],
            'unknown key' => [
                ['max_delays' => 0],
                'Unknown "rate_limit" request option "max_delays"; expected one of: enabled, bucket, identity, max_delay, max_retries.',
            ],
            'list' => [[false], 'Unknown "rate_limit" request option "0"'],
            'enabled as string' => [['enabled' => 'false'], 'The "rate_limit.enabled" request option must be a boolean, got string.'],
            'enabled as int' => [['enabled' => 0], 'The "rate_limit.enabled" request option must be a boolean, got 0.'],
            'null enabled' => [['enabled' => null], 'The "rate_limit.enabled" request option must be a boolean, got null.'],
            'empty bucket' => [['bucket' => ''], "{$bucket}, got string."],
            'blank bucket' => [['bucket' => '  '], "{$bucket}, got string."],
            'bucket as int' => [['bucket' => 42], "{$bucket}, got 42."],
            'empty identity' => [['identity' => ''], "{$identity}, got string."],
            'null identity' => [['identity' => null], "{$identity}, got null."],
            'negative max_delay' => [['max_delay' => -1], "{$maxDelay}, got -1."],
            'NAN max_delay' => [['max_delay' => NAN], "{$maxDelay}, got NAN."],
            'numeric string max_delay' => [['max_delay' => '5'], "{$maxDelay}, got string."],
            'bool max_delay' => [['max_delay' => false], "{$maxDelay}, got bool."],
            'negative max_retries' => [['max_retries' => -1], "{$maxRetries}, got -1."],
            'fractional max_retries' => [['max_retries' => 1.5], "{$maxRetries}, got 1.5."],
            'null max_retries' => [['max_retries' => null], "{$maxRetries}, got null."],
        ];
    }

    #[DataProvider('invalidOptions')]
    public function test_rejects_invalid_values(mixed $value, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        RequestOptions::fromArray($value, new Options());
    }
}
