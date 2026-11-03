<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Parser;

use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\Parser\HeaderRateLimitParser;
use Kyzegs\GuzzleRateLimitMiddleware\Parser\IetfRateLimitParser;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Testing\FakeClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IetfRateLimitParserTest extends TestCase
{
    public function test_reads_a_policy_with_its_quota(): void
    {
        $rateLimit = $this->parse([
            'RateLimit-Policy' => '"default";q=100;w=60',
            'RateLimit' => '"default";r=50;t=30',
        ]);

        $this->assertEquals(new RateLimit(limit: 100, remaining: 50, reset: 1_030.0), $rateLimit);
    }

    public function test_reads_a_policy_without_a_policy_field(): void
    {
        $rateLimit = $this->parse(['RateLimit' => '"default";r=50;t=30']);

        $this->assertEquals(new RateLimit(limit: 0, remaining: 50, reset: 1_030.0), $rateLimit);
    }

    public function test_tracks_the_policy_with_the_lowest_remaining(): void
    {
        $rateLimit = $this->parse([
            'RateLimit-Policy' => '"burst";q=10;w=1, "daily";q=1000;w=86400',
            'RateLimit' => '"daily";r=950;t=5000, "burst";r=3;t=1',
        ]);

        $this->assertEquals(new RateLimit(limit: 10, remaining: 3, reset: 1_001.0), $rateLimit);
    }

    public function test_among_exhausted_policies_tracks_the_latest_reset(): void
    {
        $rateLimit = $this->parse([
            'RateLimit-Policy' => '"burst";q=10;w=1, "daily";q=1000;w=86400',
            'RateLimit' => '"burst";r=0;t=1, "daily";r=0;t=5000',
        ]);

        $this->assertEquals(new RateLimit(limit: 1000, remaining: 0, reset: 6_000.0), $rateLimit);
    }

    public function test_combines_repeated_field_lines(): void
    {
        $rateLimit = $this->parse([
            'RateLimit' => ['"burst";r=9;t=1', '"daily";r=2;t=5000'],
        ]);

        $this->assertSame(2, $rateLimit?->remaining);
    }

    public function test_ignores_partition_keys_and_unknown_parameters(): void
    {
        $rateLimit = $this->parse([
            'RateLimit-Policy' => '"peruser";q=100;w=60;pk=:dXNlcjEyMw==:;qu="requests"',
            'RateLimit' => '"peruser";r=40;t=20;pk=:dXNlcjEyMw==:;future=?1',
        ]);

        $this->assertEquals(new RateLimit(limit: 100, remaining: 40, reset: 1_020.0), $rateLimit);
    }

    public function test_skips_policies_that_do_not_count_requests(): void
    {
        $rateLimit = $this->parse([
            'RateLimit-Policy' => '"bytes";q=1000000;qu="content-bytes", "calls";q=100;w=60',
            'RateLimit' => '"bytes";r=2, "calls";r=60;t=30',
        ]);

        $this->assertEquals(new RateLimit(limit: 100, remaining: 60, reset: 1_030.0), $rateLimit);
    }

    public function test_missing_reset_falls_back_to_the_policy_window(): void
    {
        $rateLimit = $this->parse([
            'RateLimit-Policy' => '"default";q=100;w=60',
            'RateLimit' => '"default";r=0',
        ]);

        $this->assertSame(1_060.0, $rateLimit?->reset);
    }

    public function test_missing_reset_and_window_fall_back_to_retry_after(): void
    {
        $rateLimit = $this->parse(['RateLimit' => '"default";r=0', 'Retry-After' => '12']);

        $this->assertSame(1_012.0, $rateLimit?->reset);
    }

    public function test_malformed_policy_field_is_ignored(): void
    {
        $rateLimit = $this->parse([
            'RateLimit-Policy' => '"default";q=',
            'RateLimit' => '"default";r=50;t=30',
        ]);

        $this->assertEquals(new RateLimit(limit: 0, remaining: 50, reset: 1_030.0), $rateLimit);
    }

    public function test_reads_the_older_dictionary_form(): void
    {
        $rateLimit = $this->parse(['RateLimit' => 'limit=100, remaining=50, reset=30']);

        $this->assertEquals(new RateLimit(limit: 100, remaining: 50, reset: 1_030.0), $rateLimit);
    }

    public function test_falls_back_to_the_separate_legacy_headers(): void
    {
        $rateLimit = $this->parse([
            'RateLimit-Limit' => '100',
            'RateLimit-Remaining' => '42',
            'RateLimit-Reset' => '15',
        ]);

        $this->assertEquals(new RateLimit(limit: 100, remaining: 42, reset: 1_015.0), $rateLimit);
    }

    public function test_without_any_rate_limit_field_behaves_like_the_legacy_parser(): void
    {
        $clock = new FakeClock(1_000.0);
        $response = new Response(200);

        $this->assertEquals(
            (new HeaderRateLimitParser(Headers::ietf()))->parse($response, $clock),
            (new IetfRateLimitParser())->parse($response, $clock),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedFields(): array
    {
        return [
            'no remaining' => ['"default";t=30'],
            'remaining is not a number' => ['"default";r="50";t=30'],
            'remaining is a decimal' => ['"default";r=5.5'],
            'unterminated string' => ['"default;r=50'],
            'trailing comma' => ['"default";r=50,'],
            'garbage' => ['50 requests left'],
            'dictionary without remaining' => ['limit=100, reset=30'],
            'non-ascii' => ["\"d\u{e9}faut\";r=50"],
        ];
    }

    #[DataProvider('malformedFields')]
    public function test_malformed_field_returns_null(string $field): void
    {
        $this->assertNull($this->parse(['RateLimit' => $field]));
    }

    public function test_malformed_field_does_not_fall_back_to_legacy_headers(): void
    {
        $this->assertNull($this->parse(['RateLimit' => 'nonsense;;', 'RateLimit-Remaining' => '5']));
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private function parse(array $headers): ?RateLimit
    {
        return (new IetfRateLimitParser())->parse(new Response(200, $headers), new FakeClock(1_000.0));
    }
}
