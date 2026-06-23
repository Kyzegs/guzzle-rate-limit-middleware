<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Support\HeaderParser;
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
}
