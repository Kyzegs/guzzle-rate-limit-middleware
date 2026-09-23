<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Support\HeaderParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeaderParserTest extends TestCase
{
    public function test_returns_value_when_present(): void
    {
        $response = new Response(200, ['X-Foo' => 'bar']);

        $this->assertSame('bar', HeaderParser::value($response, 'X-Foo'));
    }

    public function test_returns_fallback_when_absent_or_null_name(): void
    {
        $response = new Response(200);

        $this->assertSame('default', HeaderParser::value($response, 'X-Foo', 'default'));
        $this->assertSame('default', HeaderParser::value($response, null, 'default'));
    }

    public function test_empty_header_returns_fallback(): void
    {
        $response = new Response(200, ['X-Foo' => '']);

        $this->assertSame('fallback', HeaderParser::value($response, 'X-Foo', 'fallback'));
    }

    public function test_has_reports_presence(): void
    {
        $response = new Response(200, ['X-Foo' => 'bar']);

        $this->assertTrue(HeaderParser::has($response, 'X-Foo'));
        $this->assertFalse(HeaderParser::has($response, 'X-Missing'));
        $this->assertFalse(HeaderParser::has($response, null));
    }

    public function test_delay_reads_delta_seconds(): void
    {
        $this->assertSame(120.0, HeaderParser::delay(new Response(429, ['Retry-After' => '120']), 'Retry-After', 0.0));
        $this->assertSame(1.5, HeaderParser::delay(new Response(429, ['Retry-After' => '1.5']), 'Retry-After', 0.0));
        $this->assertSame(0.0, HeaderParser::delay(new Response(429, ['Retry-After' => '-3']), 'Retry-After', 0.0));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function httpDates(): iterable
    {
        yield 'IMF-fixdate' => ['Sun, 06 Nov 1994 08:50:07 GMT'];
        yield 'obsolete RFC 850' => ['Sunday, 06-Nov-94 08:50:07 GMT'];
        yield 'obsolete asctime' => ['Sun Nov  6 08:50:07 1994'];
    }

    #[DataProvider('httpDates')]
    public function test_delay_reads_http_dates(string $date): void
    {
        $now = 784111777.0; // Sun, 06 Nov 1994 08:49:37 GMT

        $this->assertSame(30.0, HeaderParser::delay(new Response(429, ['Retry-After' => $date]), 'Retry-After', $now));
    }

    public function test_delay_in_the_past_is_zero(): void
    {
        $response = new Response(429, ['Retry-After' => 'Sun, 06 Nov 1994 08:49:37 GMT']);

        $this->assertSame(0.0, HeaderParser::delay($response, 'Retry-After', 784111800.0));
    }

    public function test_delay_is_null_when_absent_or_unparseable(): void
    {
        $this->assertNull(HeaderParser::delay(new Response(429), 'Retry-After', 0.0));
        $this->assertNull(HeaderParser::delay(new Response(429, ['Retry-After' => 'soon']), 'Retry-After', 0.0));
        $this->assertNull(HeaderParser::delay(new Response(429, ['Retry-After' => '5']), null, 0.0));
    }
}
