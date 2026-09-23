<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Parser;

use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use Kyzegs\GuzzleRateLimitMiddleware\Parser\FractionHeaderParser;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Testing\FakeClock;
use PHPUnit\Framework\TestCase;

final class FractionHeaderParserTest extends TestCase
{
    private const HEADER = 'X-Shopify-Shop-Api-Call-Limit';

    public function test_reads_used_over_limit(): void
    {
        $rateLimit = $this->parse('32/40');

        $this->assertNotNull($rateLimit);
        $this->assertSame(40, $rateLimit->limit);
        $this->assertSame(8, $rateLimit->remaining);
        // 32 used at 2/s drain in 16s, when the full 40 is back.
        $this->assertSame(1_016.0, $rateLimit->reset);
    }

    public function test_full_bucket_resets_after_one_leak(): void
    {
        $rateLimit = $this->parse('40/40');

        $this->assertNotNull($rateLimit);
        $this->assertSame(0, $rateLimit->remaining);
        $this->assertSame(1_000.5, $rateLimit->reset);
    }

    public function test_over_full_bucket_waits_for_the_overflow_to_leak(): void
    {
        $rateLimit = $this->parse('41/40');

        $this->assertNotNull($rateLimit);
        $this->assertSame(0, $rateLimit->remaining);
        $this->assertSame(1_001.0, $rateLimit->reset);
    }

    public function test_empty_bucket_has_nothing_to_wait_for(): void
    {
        $rateLimit = $this->parse('0/40');

        $this->assertNotNull($rateLimit);
        $this->assertSame(40, $rateLimit->remaining);
        $this->assertSame(1_000.0, $rateLimit->reset);
    }

    public function test_uses_the_configured_leak_rate(): void
    {
        $parser = new FractionHeaderParser(self::HEADER, leakPerSecond: 4.0);

        $rateLimit = $parser->parse(new Response(200, [self::HEADER => '40/80']), new FakeClock(1_000.0));

        $this->assertNotNull($rateLimit);
        $this->assertSame(1_010.0, $rateLimit->reset);
    }

    public function test_tolerates_surrounding_whitespace(): void
    {
        $this->assertSame(8, $this->parse(' 32 / 40 ')?->remaining);
    }

    public function test_missing_header_returns_null(): void
    {
        $parser = new FractionHeaderParser(self::HEADER);

        $this->assertNull($parser->parse(new Response(200), new FakeClock(1_000.0)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidValues(): array
    {
        return [
            'no fraction' => ['32'],
            'missing limit' => ['32/'],
            'missing used' => ['/40'],
            'zero limit' => ['0/0'],
            'negative' => ['-1/40'],
            'decimal' => ['3.5/40'],
            'three parts' => ['32/40/2'],
            'words' => ['lots/40'],
        ];
    }

    /**
     * @dataProvider invalidValues
     */
    public function test_invalid_values_return_null(string $value): void
    {
        $this->assertNull($this->parse($value));
    }

    public function test_rejects_a_leak_rate_that_never_drains(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FractionHeaderParser(self::HEADER, leakPerSecond: 0.0);
    }

    private function parse(string $value): ?RateLimit
    {
        return (new FractionHeaderParser(self::HEADER))->parse(
            new Response(200, [self::HEADER => $value]),
            new FakeClock(1_000.0),
        );
    }
}
