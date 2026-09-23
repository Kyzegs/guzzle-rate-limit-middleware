<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Parser;

use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\Parser\HeaderRateLimitParser;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\FakeClock;
use PHPUnit\Framework\TestCase;

final class HeaderRateLimitParserTest extends TestCase
{
    public function test_reads_the_configured_headers(): void
    {
        $response = new Response(200, [
            'X-RateLimit-Limit' => '100',
            'X-RateLimit-Remaining' => '42',
            'X-RateLimit-Reset' => '15',
        ]);

        $rateLimit = (new HeaderRateLimitParser())->parse($response, new FakeClock(1_000.0));

        $this->assertNotNull($rateLimit);
        $this->assertSame(100, $rateLimit->limit);
        $this->assertSame(42, $rateLimit->remaining);
        $this->assertSame(1_015.0, $rateLimit->reset);
    }

    public function test_matches_rate_limit_from_response(): void
    {
        $clock = new FakeClock(1_000.0);
        $response = new Response(200, [
            'X-RateLimit-Limit' => '5',
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset-After' => '2.5',
            'X-RateLimit-Bucket' => 'abcd',
        ]);

        $this->assertEquals(
            RateLimit::fromResponse($response, Headers::discord(), $clock),
            (new HeaderRateLimitParser(Headers::discord()))->parse($response, $clock),
        );
    }

    public function test_response_without_headers_still_yields_a_snapshot(): void
    {
        $rateLimit = (new HeaderRateLimitParser())->parse(new Response(200), new FakeClock(1_000.0));

        $this->assertEquals(new RateLimit(), $rateLimit);
    }
}
