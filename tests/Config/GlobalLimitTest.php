<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Config;

use InvalidArgumentException;
use Kyzegs\GuzzleRateLimitMiddleware\Config\GlobalLimit;
use PHPUnit\Framework\TestCase;

final class GlobalLimitTest extends TestCase
{
    public function test_exempts_nothing_by_default(): void
    {
        $this->assertSame([], (new GlobalLimit())->exemptPaths);
    }

    public function test_rejects_an_exempt_path_that_is_not_a_valid_regex(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"#/interactions/(#"');

        new GlobalLimit(exemptPaths: ['#^/health$#', '#/interactions/(#']);
    }

    public function test_discord_is_fifty_per_second_with_interaction_callbacks_exempt(): void
    {
        $limit = GlobalLimit::discord();

        $this->assertSame(50, $limit->maxRequests);
        $this->assertSame(1.0, $limit->windowSeconds);
        $this->assertCount(1, $limit->exemptPaths);
        $this->assertMatchesRegularExpression($limit->exemptPaths[0], '/api/v10/interactions/123/tok-abc/callback');
        $this->assertDoesNotMatchRegularExpression($limit->exemptPaths[0], '/api/v10/webhooks/123/tok-abc');
    }

    public function test_with_exempt_paths_replaces_the_list_and_keeps_the_budget(): void
    {
        $original = new GlobalLimit(maxRequests: 10, windowSeconds: 2.5, exemptPaths: ['#^/health$#']);

        $copy = $original->withExemptPaths(['#^/status$#']);

        $this->assertSame(10, $copy->maxRequests);
        $this->assertSame(2.5, $copy->windowSeconds);
        $this->assertSame(['#^/status$#'], $copy->exemptPaths);
        $this->assertSame(['#^/health$#'], $original->exemptPaths, 'The original must be left untouched.');
    }

    public function test_with_exempt_paths_rejects_an_invalid_regex(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new GlobalLimit())->withExemptPaths(['not a pattern']);
    }
}
